<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ComposerReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
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

    public function __construct(
        private readonly ComposerLockReader $composerLockReader
    ) {
    }

    public function getName(): string
    {
        return 'composer';
    }

    public function getLabel(): string
    {
        return 'Composer';
    }

    public function getDescription(): string
    {
        return 'composer.lock hash plus a small watch-list of key platform package versions.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $contents = $this->composerLockReader->getRawContents();

        if ($contents === null) {
            return [
                'general' => Section::facts('general', 'General', '', ['lock_hash' => Field::varchar('Lock Hash', '')]),
                'key_packages' => Section::facts('key_packages', 'Key Packages', '', []),
            ];
        }

        return [
            'general' => Section::facts('general', 'General', '', [
                'lock_hash' => Field::varchar('Lock Hash', 'sha256:' . hash('sha256', $contents)),
            ]),
            'key_packages' => Section::facts('key_packages', 'Key Packages', '', $this->extractKeyPackages()),
        ];
    }

    /**
     * @return array<string, \StackNuts\StackGauge\Api\Field\VarcharField>
     */
    private function extractKeyPackages(): array
    {
        $keyPackages = [];
        $data = $this->composerLockReader->getDecoded();

        foreach ($data['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            if ($name !== null && in_array($name, self::KEY_PACKAGES, true)) {
                $keyPackages[$name] = Field::varchar($name, (string)($package['version'] ?? ''));
            }
        }

        return $keyPackages;
    }
}
