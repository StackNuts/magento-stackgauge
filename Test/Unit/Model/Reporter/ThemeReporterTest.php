<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\ThemeReporter;

class ThemeReporterTest extends TestCase
{
    public function testReportsEachStoreViewsThemeAndTheAdminTheme(): void
    {
        $stores = [];
        foreach ([[1, 'default'], [2, 'uk']] as [$id, $code]) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn($code);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, $scope = null, $code = null): string => $code === 1 ? '3' : '4'
        );

        $themeProvider = $this->createMock(ThemeProviderInterface::class);
        $themeProvider->method('getThemeById')->willReturnCallback(
            static fn (int $id): object => new class ($id) {
                public function __construct(private int $id)
                {
                }

                public function getThemePath(): string
                {
                    return $this->id === 3 ? 'Magento/luma' : 'Acme/custom';
                }
            }
        );
        $design = $this->createMock(DesignInterface::class);
        $design->method('getConfigurationDesignTheme')->with('adminhtml')->willReturn('Magento/backend');

        $rows = (new ThemeReporter($scopeConfig, $storeManager, $themeProvider, $design, new Field(), new Section()))
            ->getStatus()['themes']->getRows();

        $this->assertCount(3, $rows);
        $this->assertSame('frontend:default', $rows[0]->getValue()['name']->getValue());
        $this->assertSame('Magento/luma', $rows[0]->getValue()['theme_path']->getValue());
        $this->assertSame('Acme/custom', $rows[1]->getValue()['theme_path']->getValue());
        $this->assertSame('Magento/backend', $rows[2]->getValue()['theme_path']->getValue());
    }
}
