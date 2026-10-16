<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter\Pricing;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\Pricing\PricingQueries;

class PricingQueriesTest extends TestCase
{
    public function testTopSellerCatalogPriceIsLookedUpBySkuBeforeProductId(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('quote')->willReturnCallback(static fn ($v): string => "'" . $v . "'");
        $connection->method('fetchOne')->willReturn('77');

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        $connection->expects($this->exactly(3))->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                // Configurable child sold via parent product_id 100 (parent has no price).
                ['sku' => 'CHILD', 'name' => 'C', 'product_id' => '100', 'qty' => '2', 'orders' => '1', 'paid_total' => '20'],
                // SKU matches no product; falls back to product_id 200.
                ['sku' => 'GONE', 'name' => 'G', 'product_id' => '200', 'qty' => '1', 'orders' => '1', 'paid_total' => '5'],
                // Neither matches.
                ['sku' => 'NONE', 'name' => 'N', 'product_id' => '300', 'qty' => '1', 'orders' => '1', 'paid_total' => '5'],
            ],
            [['entity_id' => '55', 'sku' => 'CHILD', 'price' => '25.00']],
            [['entity_id' => '200', 'sku' => 'RENAMED', 'price' => '9.00']]
        );

        $rows = (new PricingQueries($resource, $pool))->topSellers(new \DateTimeImmutable());

        $this->assertSame(25.0, $rows[0]['catalog_price']);
        $this->assertSame(9.0, $rows[1]['catalog_price']);
        $this->assertNull($rows[2]['catalog_price']);
        $this->assertSame(10.0, $rows[0]['avg_paid']);
    }
}
