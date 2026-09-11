<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Util\Clock;
use Magento\Framework\DB\Sql\Expression;

/**
 * Lifetime order/quote counts, plus a recent hourly count/revenue breakdown. Lifetime counts
 * are reported as ever-increasing counters and alerted on via a rolling AGGREGATION_DELTA
 * window, rather than a "since midnight" reset, which would need store-timezone handling and
 * produce false positives every night just after midnight. The hourly breakdown is a separate
 * grouped SQL aggregate, so it stays a plain descriptive field rather than a trackable metric.
 */
class SalesReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    private const METRIC_ORDERS_LIFETIME = 'sales.orders_lifetime_count';
    private const METRIC_QUOTES_WITH_ITEMS_LIFETIME = 'sales.quotes_with_items_lifetime_count';

    private const HOURLY_WINDOW_HOURS = 24;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock
    ) {
    }

    public function getName(): string
    {
        return 'sales';
    }

    public function getLabel(): string
    {
        return 'Sales';
    }

    public function getDescription(): string
    {
        return 'Lifetime order/quote counts, plus hourly order counts and revenue for the recent window.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        return [
            'general' => Section::facts('general', 'General', 'Lifetime order/quote counts.', [
                'orders_lifetime_count' => Field::trackableNumber(
                    'Orders (Lifetime)',
                    $this->count('sales_order'),
                    self::METRIC_ORDERS_LIFETIME,
                    MetricDefinition::AGGREGATION_DELTA
                ),
                'quotes_with_items_lifetime_count' => Field::trackableNumber(
                    'Quotes With Items (Lifetime)',
                    $this->count('quote', 'items_count > 0'),
                    self::METRIC_QUOTES_WITH_ITEMS_LIFETIME,
                    MetricDefinition::AGGREGATION_DELTA
                ),
            ]),
            'orders_hourly' => Section::table(
                'orders_hourly',
                'Orders (hourly)',
                'Hourly order counts and revenue for the recent window.',
                $this->hourlyBuckets(),
                keyName: 'hour'
            ),
        ];
    }

    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_ORDERS_LIFETIME,
                'Orders (Lifetime)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_LT,
                5,
                360
            ),
            new MetricDefinition(
                self::METRIC_QUOTES_WITH_ITEMS_LIFETIME,
                'Quotes With Items (Lifetime)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_LT,
                3,
                360
            ),
        ];
    }

    /**
     * Deliberately does not catch failures here - a query failure should surface as this
     * whole reporter's block becoming {"error": ...} via ReporterPool's own error isolation,
     * not silently report "0" as if that were a real (and highly alertable) order count.
     */
    private function count(string $table, ?string $where = null): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);

        $select = $connection->select()
            ->from($tableName, ['cnt' => new Expression('COUNT(*)')]);

        if ($where !== null) {
            $select->where($where);
        }

        return (int) $connection->fetchOne($select);
    }

    /**
     * @return list<ArrayField>
     */
    private function hourlyBuckets(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');

        $now = $this->clock->now();
        $windowStart = $now->modify(sprintf('-%d hours', self::HOURLY_WINDOW_HOURS));

        $select = $connection->select()
            ->from($table, [
                'hour_bucket' => new Expression("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')"),
                'cnt' => new Expression('COUNT(*)'),
                'revenue' => new Expression('SUM(base_grand_total)'),
            ])
            ->where('created_at >= ?', $windowStart->format('Y-m-d H:i:s'))
            ->group('hour_bucket');

        $rows = $connection->fetchAll($select);

        $buckets = [];
        $cursor = $windowStart;
        while ($cursor <= $now) {
            $hourKey = $cursor->format('Y-m-d H:00:00');
            $buckets[$hourKey] = ['hour' => $hourKey, 'count' => 0, 'revenue' => 0.0];
            $cursor = $cursor->modify('+1 hour');
        }

        foreach ($rows as $row) {
            $key = (string) $row['hour_bucket'];
            if (isset($buckets[$key])) {
                $buckets[$key]['count'] = (int) $row['cnt'];
                $buckets[$key]['revenue'] = round((float) $row['revenue'], 2);
            }
        }

        $rows = [];
        foreach (array_values($buckets) as $bucket) {
            $rows[] = Field::array($bucket['hour'], [
                'hour' => Field::datetime('Hour', $bucket['hour']),
                'count' => Field::number('Count', $bucket['count']),
                'revenue' => Field::number('Revenue', $bucket['revenue']),
            ]);
        }

        return $rows;
    }
}
