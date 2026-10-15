<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;
use Throwable;

/**
 * Every registered module (enabled or not) with its version.
 *
 * Most Magento/Mage-OS core modules no longer declare module.xml's setup_version
 * (versioning moved to Composer), so a naive read of FullModuleList's setup_version comes
 * back null for nearly every core module - and a module developed as its own git checkout
 * dropped straight into app/code (not required via Composer at all, so absent from
 * composer.lock even though it ships its own composer.json) is exactly the case none of that
 * helps either. Resolution instead tries, in order: composer.lock (by package name, read from
 * the module's own composer.json - the most authoritative source when Composer actually
 * manages the module); that same composer.json's own "version" field, if explicitly set
 * (uncommon - most Magento modules rely on VCS tags instead - but authoritative when present);
 * a dedicated version.json file some modules ship specifically to solve this problem;
 * module.xml's setup_version; and finally an "@version" tag in registration.php's docblock, a
 * last-resort convention some modules use. A module matching none of these reports version
 * "unknown" rather than guessing.
 */
class ModuleReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface, DeclaresSubjectInterface, DeclaresSlotInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.1';

    /**
     * @param FullModuleList $fullModuleList
     * @param ModuleListInterface $enabledModuleList
     * @param ComponentRegistrar $componentRegistrar
     * @param Json $json
     * @param ComposerLockReader $composerLockReader
     * @param File $filesystemDriver
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly FullModuleList $fullModuleList,
        private readonly ModuleListInterface $enabledModuleList,
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly Json $json,
        private readonly ComposerLockReader $composerLockReader,
        private readonly File $filesystemDriver,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the modules reporter.
     */
    public function getName(): string
    {
        return 'modules';
    }

    /**
     * Human-readable label for the modules reporter block.
     */
    public function getLabel(): string
    {
        return 'Modules';
    }

    /**
     * One-line summary of what the modules reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Every registered module (enabled or not), with its resolved code version.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports every registered module's name, Composer package, resolved version, version source, and enabled flag.
     */
    public function getStatus(): array
    {
        $lockedVersions = $this->readComposerLockVersions();
        $modules = [];

        foreach ($this->fullModuleList->getAll() as $name => $info) {
            $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $name);
            $composerJson = $modulePath !== null ? $this->readModuleComposerJson($modulePath) : null;
            $packageName = $composerJson['name'] ?? null;

            [$version, $source] = $this->resolveVersion(
                $packageName,
                is_string($composerJson['version'] ?? null) ? $composerJson['version'] : null,
                $lockedVersions,
                $modulePath,
                $info['setup_version'] ?? null
            );

            $modules[] = $this->field->array($name, [
                'name' => $this->field->varchar('Name', $name),
                // The Composer package name (e.g. "magento/module-catalog"), not just the
                // Magento module name - needed on the dashboard side to look up the latest
                // available version from Packagist/Marketplace/vendor repositories, which are
                // keyed by package name and have no idea what "Magento_Catalog" is.
                'package' => $this->field->varchar('Composer Package', $packageName ?? ''),
                'version' => $this->field->varchar('Version', $version ?? ''),
                'version_source' => $this->field->varchar('Version Source', $source),
                'enabled' => $this->field->bool('Enabled', $this->enabledModuleList->has($name)),
            ]);
        }

        return [
            'modules' => $this->section->table('modules', 'Installed Modules', $this->getDescription(), $modules),
        ];
    }

    /**
     * Resolves a module's version - see this class's own docblock for the full fallback order.
     *
     * @param string|null $packageName
     * @param string|null $composerJsonVersion
     * @param array<string,string> $lockedVersions
     * @param string|null $modulePath
     * @param string|null $setupVersion
     * @return array{0: ?string, 1: string} [version, source]
     */
    private function resolveVersion(
        ?string $packageName,
        ?string $composerJsonVersion,
        array $lockedVersions,
        ?string $modulePath,
        ?string $setupVersion
    ): array {
        if ($packageName !== null && isset($lockedVersions[$packageName])) {
            return [$lockedVersions[$packageName], 'composer_lock'];
        }

        if ($composerJsonVersion) {
            return [$composerJsonVersion, 'composer_json'];
        }

        $versionJsonVersion = $modulePath !== null ? $this->readVersionJson($modulePath) : null;
        if ($versionJsonVersion !== null) {
            return [$versionJsonVersion, 'version_json'];
        }

        if ($setupVersion) {
            return [$setupVersion, 'module_xml'];
        }

        $registrationVersion = $modulePath !== null ? $this->readRegistrationDocblockVersion($modulePath) : null;
        if ($registrationVersion !== null) {
            return [$registrationVersion, 'registration_php'];
        }

        return [null, 'unknown'];
    }

    /**
     * Reads and decodes a module's own composer.json, or null if it has none or it doesn't parse.
     *
     * @param string $modulePath
     * @return array<string,mixed>|null
     */
    private function readModuleComposerJson(string $modulePath): ?array
    {
        $composerJsonPath = rtrim($modulePath, '/') . '/composer.json';
        if (!$this->filesystemDriver->isReadable($composerJsonPath)) {
            return null;
        }

        try {
            $data = $this->json->unserialize((string)$this->filesystemDriver->fileGetContents($composerJsonPath));
            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reads a dedicated version.json ({"version": "x.y.z"}) some modules ship specifically for
     * this - a convention, not a Magento or Composer standard, so this is tried only after
     * composer-based sources and only before the weaker setup_version/docblock fallbacks.
     *
     * @param string $modulePath
     */
    private function readVersionJson(string $modulePath): ?string
    {
        $path = rtrim($modulePath, '/') . '/version.json';
        if (!$this->filesystemDriver->isReadable($path)) {
            return null;
        }

        try {
            $data = $this->json->unserialize((string)$this->filesystemDriver->fileGetContents($path));
        } catch (Throwable) {
            return null;
        }

        $version = is_array($data) ? ($data['version'] ?? null) : null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Last-resort fallback: an "@version x.y.z" tag in registration.php's own leading docblock.
     * registration.php files are always tiny (a namespace plus one ComponentRegistrar::register()
     * call, maybe a leading comment) - reading the whole file is simpler than bounding a partial
     * read and costs nothing extra.
     *
     * @param string $modulePath
     */
    private function readRegistrationDocblockVersion(string $modulePath): ?string
    {
        $path = rtrim($modulePath, '/') . '/registration.php';
        if (!$this->filesystemDriver->isReadable($path)) {
            return null;
        }

        try {
            $contents = (string)$this->filesystemDriver->fileGetContents($path);
        } catch (Throwable) {
            return null;
        }

        return preg_match('/@version\s+([^\s*]+)/', $contents, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Indexes composer.lock's packages and packages-dev by package name for O(1) version lookup.
     *
     * @return array<string, string>
     */
    private function readComposerLockVersions(): array
    {
        $data = $this->composerLockReader->getDecoded();
        if ($data === null) {
            return [];
        }

        $versions = [];
        foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package) {
            if (isset($package['name'], $package['version'])) {
                $versions[$package['name']] = $package['version'];
            }
        }

        return $versions;
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'modules';
    }
}
