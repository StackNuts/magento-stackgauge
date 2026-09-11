<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\CoreReporter;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class CoreReporterTest extends TestCase
{
    private function makeReporter(string $lockContents, string $vanillaEdition = 'Community'): CoreReporter
    {
        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getEdition')->willReturn($vanillaEdition);
        $productMetadata->method('getVersion')->willReturn('2.4.7');

        $appState = $this->createMock(State::class);
        $appState->method('getMode')->willReturn('default');

        $dir = $this->createMock(ReadInterface::class);
        $dir->method('isExist')->willReturn(true);
        $dir->method('readFile')->willReturn($lockContents);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($dir);

        return new CoreReporter(
            $productMetadata,
            $appState,
            $filesystem,
            new ComposerLockReader($filesystem, new Json())
        );
    }

    public function testReportsMageOsWhenComposerLockNamesTheMageOsPackage(): void
    {
        $lock = json_encode(['packages' => [
            ['name' => 'mage-os/product-community-edition', 'version' => '3.1.0'],
        ]]);

        $status = $this->makeReporter($lock)->getStatus();

        $this->assertSame('Mage-OS', $status['general']->getFields()['edition']->getValue());
    }

    public function testFallsBackToProductMetadataEditionForVanillaMagento(): void
    {
        $lock = json_encode(['packages' => [
            ['name' => 'magento/product-community-edition', 'version' => '2.4.7'],
        ]]);

        $status = $this->makeReporter($lock)->getStatus();

        $this->assertSame('Community', $status['general']->getFields()['edition']->getValue());
    }

    public function testDeploymentModeFlagsDeveloperAsCritical(): void
    {
        $lock = json_encode(['packages' => []]);

        $status = $this->makeReporter($lock)->getStatus();

        $this->assertSame(
            ['developer'],
            $status['general']->getFields()['deployment_mode']->jsonSerialize()['critical_values']
        );
    }
}
