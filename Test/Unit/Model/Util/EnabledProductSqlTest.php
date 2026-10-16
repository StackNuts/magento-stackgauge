<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Util;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Util\EnabledProductSql;

class EnabledProductSqlTest extends TestCase
{
    private function sql(string $linkField): EnabledProductSql
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quote')->willReturnCallback(static fn ($v): string => "'" . $v . "'");
        $connection->method('fetchOne')->willReturn('97');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        return new EnabledProductSql($resource, $pool);
    }

    public function testJoinsDefaultScopeEnabledStatusOnTheLinkField(): void
    {
        $from = $this->sql('row_id')->from();

        $this->assertStringContainsString('st.row_id = e.row_id', $from);
        $this->assertStringContainsString('st.store_id = 0', $from);
        $this->assertStringContainsString('st.attribute_id = 97', $from);
        $this->assertStringContainsString('st.value = 1', $from);
    }
}
