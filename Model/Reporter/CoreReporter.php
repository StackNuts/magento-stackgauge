<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;
use Throwable;

class CoreReporter implements ReporterInterface, DeclaresSectionInterface
{
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * ProductMetadataInterface::getEdition() returns "Community" for both vanilla Magento
     * Open Source and Mage-OS, so the only reliable signal is which composer package shipped.
     */
    private const MAGE_OS_PACKAGES = [
        'mage-os/product-community-edition',
        'mage-os/framework',
    ];

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly State $appState,
        private readonly Filesystem $filesystem,
        private readonly ComposerLockReader $composerLockReader
    ) {
    }

    public function getName(): string
    {
        return 'core';
    }

    public function getLabel(): string
    {
        return 'Core';
    }

    public function getDescription(): string
    {
        return 'Edition, version, PHP version, deployment mode, and static content deploy state.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        return [
            'general' => Section::facts('general', 'General', $this->getDescription(), [
                'edition' => Field::varchar('Edition', $this->detectEdition()),
                'version' => Field::varchar('Magento Version', $this->productMetadata->getVersion()),
                'php_version' => Field::varchar('PHP Version', PHP_VERSION),
                // Visual-only flag, not an alert - too many legitimately-staged sites run
                // developer mode intentionally for this to be a useful page-someone signal.
                'deployment_mode' => Field::varchar(
                    'Deployment Mode',
                    $this->appState->getMode(),
                    [State::MODE_DEVELOPER]
                ),
                'static_content_deployed' => Field::bool('Static Content Deployed', $this->isStaticContentDeployed(), criticalWhen: false),
            ]),
        ];
    }

    private function detectEdition(): string
    {
        return $this->isMageOs() ? 'Mage-OS' : $this->productMetadata->getEdition();
    }

    private function isMageOs(): bool
    {
        $data = $this->composerLockReader->getDecoded();
        if ($data === null) {
            return false;
        }

        foreach ($data['packages'] ?? [] as $package) {
            if (in_array($package['name'] ?? null, self::MAGE_OS_PACKAGES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Proxy for "static content deployed ahead of time" vs "generated on the fly": the
     * deploy marker file setup:static-content:deploy writes to pub/static. Checked directly
     * rather than inferred from deployment mode alone, since developer-mode stores can still
     * have deployed static content and vice versa.
     */
    private function isStaticContentDeployed(): bool
    {
        try {
            return $this->filesystem
                ->getDirectoryRead(DirectoryList::STATIC_VIEW)
                ->isExist('deployed_version.txt');
        } catch (Throwable) {
            return false;
        }
    }
}
