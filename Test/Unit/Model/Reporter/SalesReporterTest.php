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
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Field\TrackableNumberField;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Reporter\SalesReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class SalesReporterTest extends TestCase
{
    private function config(bool $sendRevenue = true): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isSendRevenueEnabled')->willReturn($sendRevenue);

        return $config;
    }

    /**
     * @param list<array<string, string>> $hourlyRows
     */
    private function hourlyReporter(array $hourlyRows, bool $sendRevenue): SalesReporter
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'group', 'order', 'joinInner', 'columns'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);
        $connection->method('quote')->willReturnCallback(fn ($v) => "'".$v."'");
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchRow')->willReturn([]);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls($hourlyRows, []);

        return new SalesReporter($resource, new Clock(), $this->config($sendRevenue), new Field(), new Section());
    }

    public function testRevenueIsSplitPerBaseCurrency(): void
    {
        $hour = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:00:00');
        $rows = $this->hourlyReporter([
            ['hour_bucket' => $hour, 'cnt' => '2', 'currency' => 'GBP', 'revenue' => '50.10'],
            ['hour_bucket' => $hour, 'cnt' => '1', 'currency' => 'EUR', 'revenue' => '20.00'],
        ], true)->getStatus()['orders_hourly']->getRows();

        $thisHour = array_values(array_filter(
            $rows,
            fn ($r) => $r->getValue()['hour']->getValue() === $hour
        ));
        $this->assertCount(2, $thisHour);
        $this->assertSame('GBP', $thisHour[0]->getValue()['currency']->getValue());
        $this->assertSame(50.1, $thisHour[0]->getValue()['revenue']->getValue());
        $this->assertSame('EUR', $thisHour[1]->getValue()['currency']->getValue());
    }

    public function testRevenueFlagOffOmitsRevenueAndCurrency(): void
    {
        $hour = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:00:00');
        $rows = $this->hourlyReporter([
            ['hour_bucket' => $hour, 'cnt' => '4'],
        ], false)->getStatus()['orders_hourly']->getRows();

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('revenue', $row->getValue());
            $this->assertArrayNotHasKey('currency', $row->getValue());
        }
        $thisHour = array_values(array_filter($rows, fn ($r) => $r->getValue()['hour']->getValue() === $hour));
        $this->assertSame(4, $thisHour[0]->getValue()['count']->getValue());
    }

    public function testFirstHourlyBucketStartsOnAnHourBoundary(): void
    {
        $rows = $this->hourlyReporter([], true)->getStatus()['orders_hourly']->getRows();

        $first = $rows[0]->getValue()['hour']->getValue();
        $this->assertSame(':00:00', substr($first, -6));
        $this->assertGreaterThanOrEqual(24, count($rows));
    }

    public function testGetStatusReturnsCountsFromDb(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('columns')->willReturnSelf();

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);

        // One mocked connection serves both the orders and quotes lifetime-count queries.
        $connection->method('fetchOne')->willReturn('123');

        // The same connection also backs the hourly-buckets, baseline and stuck-payment
        // queries, which use fetchAll()/fetchRow() instead of fetchOne().
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchRow')->willReturn([]);

        $reporter = new SalesReporter($resource, new Clock(), $this->config(), new Field(), new Section());

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
        $select->method('order')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('columns')->willReturnSelf();

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('123');
        $connection->method('fetchRow')->willReturn([]);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $hour = $now->format('Y-m-d H:00:00');

        // First fetchAll() call is hourlyBuckets(), second is stuckPaymentsByMethodRows().
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [['hour_bucket' => $hour, 'cnt' => '3', 'revenue' => '123.45']],
            []
        );

        $reporter = new SalesReporter($resource, new Clock(), $this->config(), new Field(), new Section());
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

    /**
     * Builds a reporter wired to a mocked connection whose fetchRow() calls answer, in
     * order, the orderRateWindows() query then the stuckPendingPaymentWindow() query, and
     * whose fetchAll() calls answer, in order, the hourlyBuckets() query then the
     * stuckPaymentsByMethodRows() query - the same call order getStatus() makes them in.
     *
     * @param array<string, string> $orderRateRow
     * @param array<string, string|null> $stuckRow
     * @param list<array<string, string>> $methodRows
     */
    private function reporterWithMocks(
        array $orderRateRow,
        array $stuckRow,
        array $methodRows = []
    ): SalesReporter {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('columns')->willReturnSelf();

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);

        $connection->method('fetchOne')->willReturn('123');
        $connection->method('fetchRow')->willReturnOnConsecutiveCalls($orderRateRow, $stuckRow);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls([], $methodRows);

        return new SalesReporter($resource, new Clock(), $this->config(), new Field(), new Section());
    }

    public function testBaselineNormalMakesOrdersVsBaselinePercentTrackable(): void
    {
        $reporter = $this->reporterWithMocks(
            [
                'current_count' => '15',
                'week_7' => '10',
                'week_14' => '12',
                'week_21' => '8',
                'week_28' => '10',
            ],
            ['total_count' => '0', 'stuck_count' => '0', 'oldest_stuck_created_at' => null]
        );

        $fields = $reporter->getStatus()['baseline']->getFields();

        // Baseline average = (10+12+8+10)/4 = 10.0, current = 15 -> 150%.
        $this->assertSame(10.0, $fields['baseline_average']->getValue());
        $this->assertTrue($fields['baseline_sufficient']->getValue());
        $this->assertInstanceOf(TrackableNumberField::class, $fields['orders_vs_baseline_percent']);
        $this->assertSame(150.0, $fields['orders_vs_baseline_percent']->getValue());
        $this->assertSame('sales.orders_vs_baseline_percent', $fields['orders_vs_baseline_percent']->getMetricKey());
    }

    public function testBaselineBelowThresholdStaysTrackableWithLowValue(): void
    {
        $reporter = $this->reporterWithMocks(
            [
                'current_count' => '2',
                'week_7' => '10',
                'week_14' => '12',
                'week_21' => '8',
                'week_28' => '10',
            ],
            ['total_count' => '0', 'stuck_count' => '0', 'oldest_stuck_created_at' => null]
        );

        $fields = $reporter->getStatus()['baseline']->getFields();

        // Baseline average is still 10.0 (sufficient), current = 2 -> 20%, well below the
        // default alert threshold of 50 - still a trackable metric, just a low value.
        $this->assertTrue($fields['baseline_sufficient']->getValue());
        $this->assertInstanceOf(TrackableNumberField::class, $fields['orders_vs_baseline_percent']);
        $this->assertSame(20.0, $fields['orders_vs_baseline_percent']->getValue());
    }

    public function testBaselineInsufficientIsNotTrackable(): void
    {
        $reporter = $this->reporterWithMocks(
            [
                'current_count' => '1',
                'week_7' => '2',
                'week_14' => '1',
                'week_21' => '0',
                'week_28' => '1',
            ],
            ['total_count' => '0', 'stuck_count' => '0', 'oldest_stuck_created_at' => null]
        );

        $fields = $reporter->getStatus()['baseline']->getFields();

        // Baseline average = (2+1+0+1)/4 = 1.0, below BASELINE_MIN_ORDERS (5) - the field
        // must stay a plain number, not a TrackableNumberField, so it's never alerted on.
        $this->assertSame(1.0, $fields['baseline_average']->getValue());
        $this->assertFalse($fields['baseline_sufficient']->getValue());
        $this->assertNotInstanceOf(TrackableNumberField::class, $fields['orders_vs_baseline_percent']);
        $this->assertSame('number', $fields['orders_vs_baseline_percent']->getType());
    }

    public function testStuckPendingPaymentPercentTrackableWithEnoughOrders(): void
    {
        $oldestStuckAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-5 hours')
            ->format('Y-m-d H:i:s');

        $reporter = $this->reporterWithMocks(
            ['current_count' => '0', 'week_7' => '0', 'week_14' => '0', 'week_21' => '0', 'week_28' => '0'],
            ['total_count' => '20', 'stuck_count' => '5', 'oldest_stuck_created_at' => $oldestStuckAt]
        );

        $fields = $reporter->getStatus()['stuck_pending_payment']->getFields();

        // 5 / 20 * 100 = 25%, and total (20) clears STUCK_PENDING_PAYMENT_MIN_ORDERS (5).
        $this->assertSame(5, $fields['stuck_count']->getValue());
        $this->assertSame(20, $fields['total_orders_in_window']->getValue());
        $this->assertInstanceOf(TrackableNumberField::class, $fields['stuck_pending_payment_percent']);
        $this->assertSame(25.0, $fields['stuck_pending_payment_percent']->getValue());
        $this->assertSame(
            'sales.stuck_pending_payment_percent',
            $fields['stuck_pending_payment_percent']->getMetricKey()
        );
        $this->assertGreaterThan(0, $fields['oldest_stuck_order_age_minutes']->getValue());
    }

    public function testStuckPendingPaymentPercentNotTrackableWithoutEnoughOrders(): void
    {
        $reporter = $this->reporterWithMocks(
            ['current_count' => '0', 'week_7' => '0', 'week_14' => '0', 'week_21' => '0', 'week_28' => '0'],
            ['total_count' => '2', 'stuck_count' => '1', 'oldest_stuck_created_at' => null]
        );

        $fields = $reporter->getStatus()['stuck_pending_payment']->getFields();

        // Total orders in window (2) is below STUCK_PENDING_PAYMENT_MIN_ORDERS (5) - stays a
        // plain number so a near-empty store doesn't alert on one stuck order.
        $this->assertSame(2, $fields['total_orders_in_window']->getValue());
        $this->assertNotInstanceOf(TrackableNumberField::class, $fields['stuck_pending_payment_percent']);
        $this->assertSame('number', $fields['stuck_pending_payment_percent']->getType());
        $this->assertSame(0, $fields['oldest_stuck_order_age_minutes']->getValue());
    }

    public function testStuckPaymentsByMethodRowsFromDb(): void
    {
        $reporter = $this->reporterWithMocks(
            ['current_count' => '0', 'week_7' => '0', 'week_14' => '0', 'week_21' => '0', 'week_28' => '0'],
            ['total_count' => '0', 'stuck_count' => '0', 'oldest_stuck_created_at' => null],
            [
                ['method' => 'checkmo', 'stuck_count' => '3', 'oldest_created_at' => '2026-10-15 08:00:00'],
                ['method' => 'braintree', 'stuck_count' => '1', 'oldest_created_at' => '2026-10-15 12:00:00'],
            ]
        );

        $rows = $reporter->getStatus()['stuck_payments']->getRows();

        $this->assertCount(2, $rows);

        $byMethod = [];
        foreach ($rows as $row) {
            $fields = $row->getValue();
            $byMethod[$fields['method']->getValue()] = $fields;
        }

        $this->assertArrayHasKey('checkmo', $byMethod);
        $this->assertSame(3, $byMethod['checkmo']['stuck_count']->getValue());
        $this->assertSame('2026-10-15 08:00:00', $byMethod['checkmo']['oldest_created_at']->getValue());

        $this->assertArrayHasKey('braintree', $byMethod);
        $this->assertSame(1, $byMethod['braintree']['stuck_count']->getValue());

        // No customer data, increment IDs, or amounts should ever be selected.
        $this->assertSame(['method', 'stuck_count', 'oldest_created_at'], array_keys($byMethod['checkmo']));
    }
}
