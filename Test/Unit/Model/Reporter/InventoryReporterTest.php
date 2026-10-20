<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\InventoryReporter;
use StackNuts\StackGauge\Model\Util\EnabledProductSql;

class InventoryReporterTest extends TestCase
{
    /**
     * @param list<string> $capturedSql receives the queries the reporter ran
     */
    private function reporter(array &$capturedSql, bool $manageStockDefault = true): InventoryReporter
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quote')->willReturnCallback(static fn ($v): string => "'" . $v . "'");
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use (&$capturedSql) {
            $capturedSql[] = $sql;

            return '10';
        });
        $connection->method('fetchRow')->willReturnCallback(function (string $sql) use (&$capturedSql) {
            $capturedSql[] = $sql;

            return ['in_stock' => '7', 'out_of_stock' => '2'];
        });

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $enabled = $this->createMock(EnabledProductSql::class);
        $enabled->method('from')->willReturn('FROM catalog_product_entity AS e JOIN status_join AS st ON 1 ');

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->with('cataloginventory/item_options/manage_stock')
            ->willReturn($manageStockDefault);

        return new InventoryReporter($resource, $enabled, $config, new Field(), new Section());
    }

    public function testGetStatusReturnsCountsFromStockItemRows(): void
    {
        $sql = [];
        $status = $this->reporter($sql)->getStatus();

        $fields = $status['general']->getFields();
        $this->assertSame(10, $fields['total_products']->getValue());
        $this->assertSame(7, $fields['in_stock']->getValue());
        $this->assertSame(2, $fields['out_of_stock']->getValue());
        // 2 of the 9 stock-managed products.
        $this->assertSame(22.22, $fields['out_of_stock_percent']->getValue());
        $this->assertSame('inventory.out_of_stock_percent', $fields['out_of_stock_percent']->getMetricKey());
    }

    public function testQueryCountsOnlyEnabledStockManagedSellableTypesOnTheDefaultStock(): void
    {
        $sql = [];
        $this->reporter($sql)->getStatus();

        $query = $sql[1];
        $this->assertStringContainsString('status_join', $query);
        $this->assertStringContainsString("e.type_id IN ('simple','virtual','downloadable')", $query);
        $this->assertStringContainsString('si.stock_id = 1', $query);
        $this->assertStringContainsString('IF(si.use_config_manage_stock = 1, 1, si.manage_stock) = 1', $query);
    }

    public function testConfigDefaultOfStockManagementOffIsHonoured(): void
    {
        $sql = [];
        $this->reporter($sql, false)->getStatus();

        $this->assertStringContainsString('IF(si.use_config_manage_stock = 1, 0, si.manage_stock) = 1', $sql[1]);
    }

    public function testDeclaresOutOfStockAsATrackableMetric(): void
    {
        $sql = [];
        $metrics = $this->reporter($sql)->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('inventory.out_of_stock_percent', $metrics[0]->getMetricKey());
        $this->assertSame(25, $metrics[0]->getDefaultThreshold());
    }
}
