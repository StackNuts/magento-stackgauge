<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use StackNuts\StackGauge\Model\Reporter\SalesReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class SalesReporterTest extends TestCase
{
    public function testGetStatusReturnsCountsFromDb(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);

        // One mocked connection serves both the orders and quotes lifetime-count queries.
        $connection->method('fetchOne')->willReturn('123');

        // The same connection also backs the hourly-buckets query, which uses fetchAll()
        // instead of fetchOne().
        $connection->method('fetchAll')->willReturn([]);

        $reporter = new SalesReporter($resource, new Clock());

        $status = $reporter->getStatus();
        $fields = $status['general']->getFields();

        $this->assertArrayHasKey('orders_lifetime_count', $fields);
        $this->assertSame(123, $fields['orders_lifetime_count']->getValue());

        $this->assertArrayHasKey('quotes_with_items_lifetime_count', $fields);
        $this->assertSame(123, $fields['quotes_with_items_lifetime_count']->getValue());
    }

    public function testGetStatusIncludesHourlyBucketsFromDb(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('123');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $hour = $now->format('Y-m-d H:00:00');

        $connection->method('fetchAll')->willReturn([
            ['hour_bucket' => $hour, 'cnt' => '3', 'revenue' => '123.45'],
        ]);

        $reporter = new SalesReporter($resource, new Clock());
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('orders_hourly', $status);
        // Table section rows are a plain ordered list, not keyed by label, so match the
        // bucket by its own "hour" field.
        $buckets = $status['orders_hourly']->getRows();
        $matched = current(array_filter(
            $buckets,
            fn ($bucket) => $bucket->getValue()['hour']->getValue() === $hour
        ));
        $this->assertNotFalse($matched, 'Expected a bucket for '.$hour);
        $fields = $matched->getValue();
        $this->assertSame('datetime', $fields['hour']->getType());
        $this->assertSame(3, $fields['count']->getValue());
    }
}
