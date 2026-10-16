<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Shell;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Section\SectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\SecuritySectionTrait;
use StackNuts\StackGauge\Model\Util\CommandTimeout;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;
use StackNuts\StackGauge\Model\Util\JsonFileCache;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;
use Throwable;

/**
 * Applied official Adobe Commerce security patches, via `vendor/bin/patch-status` - a tool
 * bundled with Adobe Commerce itself, not the separate `magento/quality-patches` package
 * (which applies arbitrary bug-fix patches, unrelated to security). Reports "detectable:
 * false" rather than guessing when the binary isn't present. --format=json is passed
 * explicitly since the tool's own output format isn't guaranteed stable, and every field from
 * the decoded payload is read defensively (missing/wrong-typed keys are skipped, not fatal)
 * in case a future release reshapes it.
 *
 * patch-status does a genuine dry-run patch-application check (fetches each applicable
 * patch's diff, runs `patch --dry-run` against live core files), so a well-formed result is
 * cached for CACHE_TTL_SECONDS and reused rather than re-run on every getStatus() call -
 * nothing upstream of this reporter (cadence, "Send Now", the CLI) throttles how often
 * getStatus() itself can be invoked. Only a successfully decoded result is cached; the
 * "binary missing" and "unrecognized output" branches are cheap already and stay live so a
 * stale cached "not detectable" reading can never outlive the binary reappearing.
 */
class PatchReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use SecuritySectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const MAX_UNRECOGNIZED_OUTPUT_LENGTH = 1000;
    private const COMMAND_TIMEOUT_SECONDS = 120;
    private const CACHE_TTL_SECONDS = 86400;
    private const CACHE_RELATIVE_PATH = 'stackgauge/patch_status_cache.json';

    /**
     * @param Filesystem $filesystem
     * @param File $filesystemDriver
     * @param Shell $shell
     * @param Json $json
     * @param LoggerInterface $logger
     * @param Field $field
     * @param Section $section
     * @param JsonFileCache $cache
     * @param ComposerLockReader $composerLockReader
     * @param CommandTimeout $commandTimeout
     * @param LogLineRedactor $redactor
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly File $filesystemDriver,
        private readonly Shell $shell,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly Field $field,
        private readonly Section $section,
        private readonly JsonFileCache $cache,
        private readonly ComposerLockReader $composerLockReader,
        private readonly CommandTimeout $commandTimeout,
        private readonly LogLineRedactor $redactor
    ) {
    }

    /**
     * Payload key for the patches reporter.
     */
    public function getName(): string
    {
        return 'patches';
    }

    /**
     * Human-readable label for the patches reporter block.
     */
    public function getLabel(): string
    {
        return 'Patches';
    }

    /**
     * One-line summary of what the patches reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return "Applied official Adobe Commerce security patches, via vendor/bin/patch-status when it's present.";
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Runs vendor/bin/patch-status --format=json and reports its decoded output.
     *
     * Reports "detectable: false" instead if the binary is missing or its output can't be parsed.
     */
    public function getStatus(): array
    {
        $fingerprint = $this->composerLockReader->getMtime();
        $cached = $this->cache->read(self::CACHE_RELATIVE_PATH, self::CACHE_TTL_SECONDS, $fingerprint);
        if ($cached !== null) {
            return $this->result(true, null, $cached);
        }

        $root = rtrim($this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath(), '/');
        $binary = $root . '/vendor/bin/patch-status';

        // is_executable() has no Magento\Framework\Filesystem\DriverInterface equivalent -
        // the driver abstraction only wraps read/write/stat operations, not exec-bit checks.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        if (!$this->filesystemDriver->isFile($binary) || !is_executable($binary)) {
            return $this->result(false);
        }

        // Run from the Magento root with an explicit --root (the tool defaults to "."), with
        // stdin closed (it prompts for repo.magento.com keys otherwise) and stderr dropped.
        // The group keeps Shell's appended "2>&1" from re-merging stderr into the output.
        $command = '{ cd %s && ' . $this->commandTimeout->prefix(self::COMMAND_TIMEOUT_SECONDS)
            . '%s --root=%s --format=json < /dev/null 2>/dev/null; }';

        try {
            $output = $this->shell->execute($command, [$root, $binary, $root]);
        } catch (Throwable $e) {
            // The tool exits 1 after printing usable JSON when the registry is unreachable.
            $output = $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : '';
            if (!is_array($this->decode($output))) {
                $this->logger->warning('StackGauge: vendor/bin/patch-status execution failed: ' . $e->getMessage());

                return $this->result(false);
            }
        }

        $data = $this->decode($output);

        if (!is_array($data)) {
            return $this->result(true, $this->sanitizeOutput($output));
        }

        $this->cache->write(self::CACHE_RELATIVE_PATH, $data, $fingerprint);

        return $this->result(true, null, $data);
    }

    /**
     * @param string $output
     * @return array<string,mixed>|null
     */
    private function decode(string $output): ?array
    {
        try {
            $data = $this->json->unserialize($output);
        } catch (Throwable $e) {
            $this->logger->warning('StackGauge: vendor/bin/patch-status returned unparseable JSON: ' . $e->getMessage());

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Redacts secrets/paths-with-credentials from unparseable tool output and caps its length.
     *
     * @param string $output
     */
    private function sanitizeOutput(string $output): string
    {
        return substr($this->redactor->redact(trim($output)), 0, self::MAX_UNRECOGNIZED_OUTPUT_LENGTH);
    }

    /**
     * Assembles the getStatus() payload from patch-status's decoded output (or lack of one).
     *
     * @param bool $detectable
     * @param string|null $unrecognizedOutput
     * @param array<string,mixed>|null $data
     * @return array<string, SectionInterface>
     */
    private function result(bool $detectable, ?string $unrecognizedOutput = null, ?array $data = null): array
    {
        $generalFields = ['detectable' => $this->field->bool('Detectable', $detectable)];

        if (is_string($data['base_version'] ?? null)) {
            $generalFields['base_version'] = $this->field->varchar('Base Version', $data['base_version']);
        }

        if (is_string($data['registry_source'] ?? null)) {
            $generalFields['registry_source'] = $this->field->varchar('Registry Source', $data['registry_source']);
        }

        if ($unrecognizedOutput !== null) {
            $generalFields['unrecognized_output'] = $this->field->varchar('Unrecognized Output', $unrecognizedOutput);
        }

        return [
            'general' => $this->section->facts('general', 'General', '', $generalFields),
            'installed_components' => $this->section->table(
                'installed_components',
                'Installed Components',
                'Component versions reported by patch-status.',
                $this->componentRows(is_array($data['installed_components'] ?? null) ? $data['installed_components'] : []),
                keyName: 'component'
            ),
            'applied_patches' => $this->section->table(
                'applied_patches',
                'Applied Patches',
                'Patches patch-status confirms are applied.',
                $this->patchIdRows(is_array($data['applied_patches'] ?? null) ? $data['applied_patches'] : []),
                keyName: 'patch_id'
            ),
            'missing_patches' => $this->section->table(
                'missing_patches',
                'Missing Patches',
                'Patches patch-status expects but did not find applied.',
                $this->patchIdRows(is_array($data['missing_patches'] ?? null) ? $data['missing_patches'] : []),
                keyName: 'patch_id'
            ),
            'unknown_patches' => $this->section->table(
                'unknown_patches',
                'Unknown Patches',
                'Applied patches patch-status does not recognize.',
                $this->patchIdRows(is_array($data['unknown_patches'] ?? null) ? $data['unknown_patches'] : []),
                keyName: 'patch_id'
            ),
            'vulnerability_status' => $this->section->table(
                'vulnerability_status',
                'Vulnerability Status',
                'Per-CVE protection status derived from applied/missing patches.',
                $this->vulnerabilityRows(
                    is_array($data['vulnerability_status'] ?? null) ? $data['vulnerability_status'] : []
                ),
                keyName: 'cve'
            ),
            'warnings' => $this->section->table(
                'warnings',
                'Warnings',
                'Warnings emitted by patch-status itself (e.g. registry fetch or auth issues).',
                $this->warningRows(is_array($data['warnings'] ?? null) ? $data['warnings'] : [])
            ),
        ];
    }

    /**
     * Builds one row per installed component reported by patch-status.
     *
     * @param array<mixed,mixed> $components
     * @return list<ArrayField>
     */
    private function componentRows(array $components): array
    {
        $rows = [];

        foreach ($components as $component => $version) {
            if (!is_string($component) || $component === '' || !is_scalar($version)) {
                continue;
            }

            $rows[] = $this->field->array($component, [
                'component' => $this->field->varchar('Component', $component),
                'version' => $this->field->varchar('Version', (string)$version),
            ]);
        }

        return $rows;
    }

    /**
     * Builds one deduplicated row per patch ID reported by patch-status.
     *
     * @param array<mixed,mixed> $patchIds
     * @return list<ArrayField>
     */
    private function patchIdRows(array $patchIds): array
    {
        $rows = [];

        foreach ($patchIds as $patchId) {
            if (!is_string($patchId) || $patchId === '' || isset($rows[$patchId])) {
                continue;
            }

            $rows[$patchId] = $this->field->array($patchId, [
                'patch_id' => $this->field->varchar('Patch ID', $patchId),
            ]);
        }

        return array_values($rows);
    }

    /**
     * Builds one row per CVE with its protection status, reported by patch-status.
     *
     * @param array<mixed,mixed> $statuses
     * @return list<ArrayField>
     */
    private function vulnerabilityRows(array $statuses): array
    {
        $rows = [];

        foreach ($statuses as $cve => $entry) {
            $status = is_array($entry) ? ($entry['status'] ?? null) : $entry;

            if (!is_string($cve) || $cve === '' || !is_string($status) || $status === '') {
                continue;
            }

            $rows[] = $this->field->array($cve, [
                'cve' => $this->field->varchar('CVE', $cve),
                'status' => $this->field->varchar('Status', $status, criticalValues: ['VULNERABLE']),
            ]);
        }

        return $rows;
    }

    /**
     * Builds one row per warning message emitted by patch-status itself.
     *
     * @param array<mixed,mixed> $warnings
     * @return list<ArrayField>
     */
    private function warningRows(array $warnings): array
    {
        $rows = [];

        foreach ($warnings as $warning) {
            if (!is_string($warning) || $warning === '') {
                continue;
            }

            $rows[] = $this->field->array($warning, [
                'message' => $this->field->varchar('Message', $warning),
            ]);
        }

        return $rows;
    }
}
