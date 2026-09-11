<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use StackNuts\StackGauge\Model\Reporter\InventoryReporter;

class InventoryReporterTest extends TestCase
{
    public function testGetStatusReturnsCounts(): void
    {
        $colTotal = $this->createMock(ProductCollection::class);
        $colTotal->method('getSize')->willReturn(10);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($colTotal);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('7');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('cataloginventory_stock_item');

        $reporter = new InventoryReporter($factory, $resourceConnection);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('general', $status);

        $fields = $status['general']->getFields();
        $this->assertSame(10, $fields['total_products']->getValue());
        $this->assertSame(7, $fields['in_stock']->getValue());
        $this->assertSame(3, $fields['out_of_stock']->getValue());
        $this->assertSame('inventory.out_of_stock_count', $fields['out_of_stock']->getMetricKey());
    }

    public function testDeclaresOutOfStockAsATrackableMetric(): void
    {
        $factory = $this->createMock(ProductCollectionFactory::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);

        $reporter = new InventoryReporter($factory, $resourceConnection);
        $metrics = $reporter->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('inventory.out_of_stock_count', $metrics[0]->getMetricKey());
    }
}
