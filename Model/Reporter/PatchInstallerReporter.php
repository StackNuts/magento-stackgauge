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
use StackNuts\StackGauge\Model\Util\ComposerLockReader;
use Throwable;

/**
 * Applied Adobe isolated security patches on Community Edition / Mage-OS, via
 * `composer magento-patches:status --json` - a command from the third-party
 * `samjuk/magento-patch-installer` Composer plugin, only meaningful when a site has also
 * required a patch-declaring package such as `samjuk/m2-meta-security-patches`. This is the
 * only isolated-patch signal available on a site with no `vendor/bin/patch-status` (Adobe
 * Commerce only - see PatchReporter) - unlike that tool, this one verifies via `git apply`
 * against the live working tree rather than the `patch` binary, so it correctly recognizes a
 * patch as applied regardless of how it actually got there (confirmed live against a site
 * patched entirely outside this tool).
 *
 * Detection is a cheap composer.lock check first (ComposerLockReader, already used by
 * ComposerReporter/CoreReporter/ModuleReporter) - `composer` is only ever shelled out to when
 * the package is actually present, since most sites won't have it and a `composer.lock` read
 * is far cheaper than an unconditional shell-out. A well-formed result is then cached for
 * CACHE_TTL_SECONDS the same way PatchReporter caches patch-status - see that class's docblock
 * for why (nothing upstream throttles how often getStatus() itself is invoked).
 */
class PatchInstallerReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use SecuritySectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const CACHE_TTL_SECONDS = 86400;
    private const CACHE_RELATIVE_PATH = 'stackgauge/patch_installer_cache.json';
    private const PACKAGE_NAME = 'samjuk/magento-patch-installer';

    /**
     * @param ComposerLockReader $composerLockReader
     * @param Filesystem $filesystem
     * @param File $filesystemDriver
     * @param Shell $shell
     * @param Json $json
     * @param LoggerInterface $logger
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ComposerLockReader $composerLockReader,
        private readonly Filesystem $filesystem,
        private readonly File $filesystemDriver,
        private readonly Shell $shell,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the patch installer reporter.
     */
    public function getName(): string
    {
        return 'patch_installer';
    }

    /**
     * Human-readable label for the patch installer reporter block.
     */
    public function getLabel(): string
    {
        return 'Patch Installer';
    }

    /**
     * One-line summary of what the patch installer reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Applied isolated security patches, via composer magento-patches:status '
            . "when samjuk/magento-patch-installer is present.";
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Runs composer magento-patches:status --json and reports its decoded output.
     *
     * Reports "detectable: false" instead if samjuk/magento-patch-installer isn't in
     * composer.lock, the shell-out fails, or its output can't be parsed.
     */
    public function getStatus(): array
    {
        if (!$this->packageInstalled()) {
            return $this->result(false);
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            return $this->result(true, $cached);
        }

        $root = rtrim($this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath(), '/');

        try {
            $output = $this->shell->execute('cd %s && composer %s', [$root, 'magento-patches:status --json']);
        } catch (Throwable $e) {
            $this->logger->warning('StackGauge: composer magento-patches:status execution failed: ' . $e->getMessage());

            return $this->result(false);
        }

        try {
            $data = $this->json->unserialize($output);
        } catch (Throwable $e) {
            $this->logger->warning(
                'StackGauge: composer magento-patches:status returned unparseable JSON: ' . $e->getMessage()
            );

            return $this->result(false);
        }

        if (!is_array($data) || !is_array($data['patches'] ?? null)) {
            return $this->result(false);
        }

        $this->writeCache($data);

        return $this->result(true, $data);
    }

    /**
     * Whether samjuk/magento-patch-installer is a locked dependency of this site.
     *
     * Checked before ever shelling out to composer, since most sites won't have it.
     */
    private function packageInstalled(): bool
    {
        $decoded = $this->composerLockReader->getDecoded();
        $packages = is_array($decoded) && is_array($decoded['packages'] ?? null) ? $decoded['packages'] : [];

        foreach ($packages as $package) {
            if (($package['name'] ?? null) === self::PACKAGE_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads a still-fresh cached magento-patches:status result, if one exists.
     *
     * Never throws - a missing, corrupt, stale or wrong-shaped cache file is treated the same
     * as no cache at all, since the caller falls back to a live run either way.
     *
     * @return array<string,mixed>|null
     */
    private function readCache(): ?array
    {
        try {
            $path = $this->cachePath();

            if (!$this->filesystemDriver->isExists($path)) {
                return null;
            }

            $decoded = $this->json->unserialize($this->filesystemDriver->fileGetContents($path));

            if (!is_array($decoded)
                || !is_int($decoded['recorded_at'] ?? null)
                || !is_array($decoded['data'] ?? null)
            ) {
                return null;
            }

            if (time() - $decoded['recorded_at'] >= self::CACHE_TTL_SECONDS) {
                return null;
            }

            return $decoded['data'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Best-effort write of a fresh magento-patches:status result to the cache.
     *
     * A write failure must not fail this reporter - the next call simply runs the command
     * live again.
     *
     * @param array<string,mixed> $data
     * @return void
     */
    private function writeCache(array $data): void
    {
        try {
            $path = $this->cachePath();
            $dir = $this->filesystemDriver->getParentDirectory($path);

            if (!$this->filesystemDriver->isDirectory($dir)) {
                $this->filesystemDriver->createDirectory($dir);
            }

            $this->filesystemDriver->filePutContents(
                $path,
                $this->json->serialize(['recorded_at' => time(), 'data' => $data])
            );
        } catch (Throwable $e) {
            $this->logger->warning('StackGauge: could not write patch installer cache: ' . $e->getMessage());
        }
    }

    /**
     * Absolute path to the cached magento-patches:status result, under var/.
     */
    private function cachePath(): string
    {
        return rtrim($this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath(), '/')
            . '/' . self::CACHE_RELATIVE_PATH;
    }

    /**
     * Assembles the getStatus() payload from magento-patches:status's decoded output.
     *
     * @param bool $detectable
     * @param array<string,mixed>|null $data
     * @return array<string, SectionInterface>
     */
    private function result(bool $detectable, ?array $data = null): array
    {
        $patches = is_array($data['patches'] ?? null) ? $data['patches'] : [];
        $applied = [];
        $notApplied = [];

        foreach ($patches as $patch) {
            if (!is_array($patch) || ($patch['applicable'] ?? null) !== true || ($patch['blocked'] ?? null) !== false) {
                continue;
            }

            if ($this->isFullyApplied($patch)) {
                $applied[] = $patch;
            } else {
                $notApplied[] = $patch;
            }
        }

        return [
            'general' => $this->section->facts('general', 'General', '', [
                'detectable' => $this->field->bool('Detectable', $detectable),
            ]),
            'applied_patches' => $this->section->table(
                'applied_patches',
                'Applied Patches',
                'Isolated patches magento-patches:status confirms are applied.',
                $this->patchRows($applied),
                keyName: 'id'
            ),
            'not_applied_patches' => $this->section->table(
                'not_applied_patches',
                'Not Applied Patches',
                'Isolated patches applicable to this site but not fully applied.',
                $this->patchRows($notApplied),
                keyName: 'id'
            ),
        ];
    }

    /**
     * A patch only counts as applied when every one of its targets is confirmed already in
     * place - a patch with no targets at all is not confirmed either way, so it's treated as
     * not applied rather than silently counted as covered.
     *
     * @param array<string,mixed> $patch
     */
    private function isFullyApplied(array $patch): bool
    {
        $targets = is_array($patch['targets'] ?? null) ? $patch['targets'] : [];

        if ($targets === []) {
            return false;
        }

        foreach ($targets as $target) {
            if (!is_array($target) || ($target['state'] ?? null) !== 'already') {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds one row per patch, keyed by its bare Adobe monthly/bulletin id.
     *
     * @param array<int,array<string,mixed>> $patches
     * @return list<ArrayField>
     */
    private function patchRows(array $patches): array
    {
        $rows = [];

        foreach ($patches as $patch) {
            $id = $patch['id'] ?? null;
            $line = $patch['line'] ?? null;

            if (!is_string($id) || $id === '') {
                continue;
            }

            $rows[] = $this->field->array($id, [
                'id' => $this->field->varchar('Patch ID', $id),
                'line' => $this->field->varchar('Base Version', is_string($line) ? $line : ''),
                'label' => $this->field->varchar('Label', is_string($patch['label'] ?? null) ? $patch['label'] : ''),
            ]);
        }

        return $rows;
    }
}
