<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\PaymentMethodsReporter;

class PaymentMethodsReporterTest extends TestCase
{
    private function store(int $id, string $code): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);

        return $store;
    }

    public function testListsMethodsActiveInAnyStoreViewWithThoseStoreViews(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->store(1, 'default'), $this->store(2, 'uk')]);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, $scope = null, $code = null) => match ($path) {
                'payment' => ['checkmo' => ['model' => 'X'], 'ukonly' => ['model' => 'Y'], 'off' => ['model' => 'Z'], 'group' => ['title' => 'x']],
                'payment/checkmo/active' => 1,
                'payment/ukonly/active' => $code === 2 ? 1 : 0,
                'payment/checkmo/title' => 'Check / Money order',
                'payment/ukonly/title' => 'UK Only',
                default => 0,
            }
        );

        $status = (new PaymentMethodsReporter($storeManager, $scopeConfig, new Field(), new Section()))->getStatus();
        $rows = $status['payments']->getRows();

        $this->assertCount(2, $rows);
        $this->assertSame('Check / Money order', $rows[0]->getValue()['title']->getValue());
        $this->assertSame('default, uk', $rows[0]->getValue()['stores']->getValue());
        $this->assertSame('uk', $rows[1]->getValue()['stores']->getValue());
        $this->assertSame('ukonly', $rows[1]->getLabel());
    }
}
