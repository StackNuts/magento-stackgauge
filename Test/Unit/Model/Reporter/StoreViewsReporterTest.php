<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use StackNuts\StackGauge\Model\Reporter\StoreViewsReporter;

class StoreViewsReporterTest extends TestCase
{
    public function testGetStatusReturnsStoreList(): void
    {
        $store1 = $this->createMock(\Magento\Store\Model\Store::class);
        $store1->method('getId')->willReturn(1);
        $store1->method('getCode')->willReturn('default');
        $store1->method('getName')->willReturn('Default Store');
        $store1->method('getBaseUrl')->willReturn('https://example.local/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store1]);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['currency/options/default', 'stores', 1, 'USD'],
            ['general/locale/code', 'stores', 1, 'en_US'],
        ]);

        $reporter = new StoreViewsReporter($storeManager, $scopeConfig);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('store_views', $status);
    }
}
