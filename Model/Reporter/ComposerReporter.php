<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ComposerReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * A small fixed watch-list, not every package in the lock file - just enough to
     * correlate a deploy with its core platform/framework version. Includes both Magento
     * Open Source and Mage-OS package names, since a Mage-OS lock file has no "magento/*"
     * packages at all.
     */
    private const KEY_PACKAGES = [
        'magento/product-community-edition',
        'magento/product-enterprise-edition',
        'magento/framework',
        'mage-os/product-community-edition',
        'mage-os/framework',
    ];

    /**
     * Hosts a package's dist/source URL is expected to come from. Deliberately not an
     * exhaustive "known good" list in the sense of flagging everything else as suspicious -
     * private Composer mirrors (a paid extension vendor's own satis/Packagist-Enterprise repo,
     * say) are common and entirely legitimate, so this check is informational, not a pass/fail
     * verdict: see extractNonStandardOriginPackages()'s own docblock.
     *
     * @var list<string>
     */
    private const ALLOWED_HOSTS = [
        'packagist.org',
        'github.com',
        'api.github.com',
        'raw.githubusercontent.com',
        'codeload.github.com',
        'gitlab.com',
        'bitbucket.org',
        'repo.magento.com',
        'magento.com',
        'repo.mage-os.org',
        'mage-os.org',
    ];

    /**
     * Host suffixes covering an entire class of mirror rather than one fixed hostname - e.g.
     * Packagist Enterprise/Teams gives every tenant their own "<name>.repo.packagist.com".
     *
     * @var list<string>
     */
    private const ALLOWED_HOST_SUFFIXES = [
        '.repo.packagist.com',
    ];

    /**
     * @param ComposerLockReader $composerLockReader
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ComposerLockReader $composerLockReader,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the composer reporter.
     */
    public function getName(): string
    {
        return 'composer';
    }

    /**
     * Human-readable label for the composer reporter block.
     */
    public function getLabel(): string
    {
        return 'Composer';
    }

    /**
     * One-line summary of what the composer reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'composer.lock hash plus a small watch-list of key platform package versions.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports composer.lock's hash, key package versions, and any non-standard package origin.
     *
     * A hash of composer.lock's raw contents, the installed version of each package in
     * KEY_PACKAGES, and any package whose dist/source host isn't one of the common ones.
     */
    public function getStatus(): array
    {
        $contents = $this->composerLockReader->getRawContents();

        if ($contents === null) {
            return [
                'general' => $this->section->facts(
                    'general',
                    'General',
                    '',
                    ['lock_hash' => $this->field->varchar('Lock Hash', '')]
                ),
                'key_packages' => $this->section->facts('key_packages', 'Key Packages', '', []),
                'package_origins' => $this->section->table('package_origins', 'Non-Standard Package Origins', '', []),
            ];
        }

        $data = $this->composerLockReader->getDecoded() ?? [];

        return [
            'general' => $this->section->facts('general', 'General', '', [
                'lock_hash' => $this->field->varchar('Lock Hash', 'sha256:' . hash('sha256', $contents)),
            ]),
            'key_packages' => $this->section->facts(
                'key_packages',
                'Key Packages',
                '',
                $this->extractKeyPackages($data)
            ),
            'package_origins' => $this->section->table(
                'package_origins',
                'Non-Standard Package Origins',
                'Packages whose download host isn\'t one of the common public registries - not '
                    . 'inherently suspicious (private vendor mirrors are common and legitimate), '
                    . 'but worth a glance for anything unexpected.',
                $this->extractNonStandardOriginPackages($data)
            ),
        ];
    }

    /**
     * Resolves the installed version of each package in KEY_PACKAGES that's present in composer.lock.
     *
     * @param array<string,mixed> $data
     * @return array<string, \StackNuts\StackGauge\Api\Field\VarcharField>
     */
    private function extractKeyPackages(array $data): array
    {
        $keyPackages = [];

        foreach ($data['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            if ($name !== null && in_array($name, self::KEY_PACKAGES, true)) {
                $keyPackages[$name] = $this->field->varchar($name, (string)($package['version'] ?? ''));
            }
        }

        return $keyPackages;
    }

    /**
     * Every package (prod or dev) whose dist URL - falling back to source URL if dist is
     * missing - resolves to a host outside ALLOWED_HOSTS/ALLOWED_HOST_SUFFIXES. Purely
     * informational (no severity coloring at all) rather than a pass/fail check: the realistic
     * common case is a legitimate private vendor mirror, not a compromise, so this is "here's
     * what's unusual, you be the judge" rather than an alert.
     *
     * @param array<string,mixed> $data
     * @return list<ArrayField>
     */
    private function extractNonStandardOriginPackages(array $data): array
    {
        $rows = [];

        foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package) {
            $name = $package['name'] ?? null;
            $url = $package['dist']['url'] ?? $package['source']['url'] ?? null;
            if ($name === null || $url === null) {
                continue;
            }

            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            $host = parse_url((string)$url, PHP_URL_HOST);
            if (!is_string($host) || $this->isAllowedHost($host)) {
                continue;
            }

            $rows[] = $this->field->array($name, [
                'package' => $this->field->varchar('Package', $name),
                'host' => $this->field->varchar('Host', $host),
            ]);
        }

        return $rows;
    }

    /**
     * Whether $host matches ALLOWED_HOSTS/ALLOWED_HOST_SUFFIXES.
     *
     * @param string $host
     */
    private function isAllowedHost(string $host): bool
    {
        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }
}
