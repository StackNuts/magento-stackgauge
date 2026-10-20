<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\Order;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Util\Clock;
use Magento\Framework\DB\Sql\Expression;

/**
 * Order/quote growth counters, a recent hourly count/revenue breakdown, order rate compared
 * with a same-weekday-and-hour baseline, and stuck pending_payment orders. The growth
 * counters are the highest entity_id on sales_order and quote - O(1) primary-key reads that
 * never decrease (a COUNT(*) on quote shrinks after the nightly sales_clean_quotes, which
 * would read as negative growth). They are alerted on via a rolling AGGREGATION_DELTA window,
 * rather than a "since midnight" reset, which would need store-timezone handling and
 * produce false positives every night just after midnight. An id high-water mark counts
 * allocated ids, not rows: a server with auto_increment_increment > 1 (e.g. Galera/replica
 * pairs, increment 2-3) advances that many ids per new row, and failed inserts burn ids, so
 * treat the delta as approximate "new orders/baskets". The quote counter includes empty
 * quotes (anonymous visitors/bots creating a quote), so it is a basket-creation signal.
 *
 * Revenue (hourly) excludes canceled orders and is split per base currency, since websites
 * may use different base currencies and summing them would be meaningless. It can be turned
 * off entirely with the "Send Revenue Figures" config flag; order counts are always sent. The hourly breakdown is a separate
 * grouped SQL aggregate, so it stays a plain descriptive field rather than a trackable metric.
 */
class SalesReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.1';

    private const METRIC_ORDERS_LIFETIME = 'sales.orders_lifetime_count';
    private const METRIC_QUOTES_WITH_ITEMS_LIFETIME = 'sales.quotes_with_items_lifetime_count';
    private const METRIC_ORDERS_VS_BASELINE_PERCENT = 'sales.orders_vs_baseline_percent';
    private const METRIC_STUCK_PENDING_PAYMENT_PERCENT = 'sales.stuck_pending_payment_percent';

    private const HOURLY_WINDOW_HOURS = 24;

    /**
     * Width of the "current" and each baseline comparison window, in hours.
     */
    private const ORDER_RATE_WINDOW_HOURS = 3;

    /**
     * How many days ago each baseline comparison window sits, one per week going back four
     * weeks. Comparing the same weekday+hour of day (rather than e.g. "yesterday") handles
     * both daily and weekly seasonality in one go. Note that since these are UTC timestamps,
     * a window up to four weeks back can land an hour off local wall-clock time across a DST
     * change - acceptable slop for a comparison baseline like this.
     *
     * @var list<int>
     */
    private const BASELINE_WEEKS_AGO = [7, 14, 21, 28];

    /**
     * Minimum baseline average order count below which orders_vs_baseline_percent is not
     * made trackable - a quiet store doing 1 order/window would otherwise swing between 0%
     * and several hundred percent and alert constantly.
     */
    private const BASELINE_MIN_ORDERS = 5;

    /**
     * A gateway redirect is considered "never came back" once a pending_payment order is
     * older than this.
     */
    private const STUCK_PENDING_PAYMENT_MAX_AGE_HOURS = 24;

    /**
     * Orders younger than this are excluded from the stuck-payment window - the customer may
     * still be mid-checkout, not yet stuck.
     */
    private const STUCK_PENDING_PAYMENT_MIN_AGE_MINUTES = 30;

    /**
     * Minimum total orders in the stuck-payment window below which
     * stuck_pending_payment_percent is not made trackable - with very few orders, one stuck
     * order already swings the percentage wildly.
     */
    private const STUCK_PENDING_PAYMENT_MIN_ORDERS = 5;

    /**
     * @param ResourceConnection $resourceConnection
     * @param Clock $clock
     * @param Config $config
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the sales reporter.
     */
    public function getName(): string
    {
        return 'sales';
    }

    /**
     * Human-readable label for the sales reporter block.
     */
    public function getLabel(): string
    {
        return 'Sales';
    }

    /**
     * One-line summary of what the sales reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Lifetime order/quote counts, hourly order counts and revenue, order rate vs the usual '
            . 'rate for this time of week, and stuck pending-payment orders.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports lifetime order and quote-with-items counts, an hourly order count/revenue
     * breakdown, order rate vs the usual rate for this time of week, and stuck
     * pending-payment orders.
     */
    public function getStatus(): array
    {
        return [
            'general' => $this->section->facts(
                'general',
                'General',
                'Highest order and quote ids (a growth counter, see the reporter docblock).',
                [
                    'orders_lifetime_count' => $this->field->trackableNumber(
                        'Orders (Lifetime)',
                        $this->maxEntityId('sales_order'),
                        self::METRIC_ORDERS_LIFETIME,
                        MetricDefinition::AGGREGATION_DELTA
                    ),
                    'quotes_with_items_lifetime_count' => $this->field->trackableNumber(
                        'Quotes With Items (Lifetime)',
                        $this->maxEntityId('quote'),
                        self::METRIC_QUOTES_WITH_ITEMS_LIFETIME,
                        MetricDefinition::AGGREGATION_DELTA
                    ),
                ]
            ),
            'orders_hourly' => $this->section->table(
                'orders_hourly',
                'Orders (hourly)',
                'Hourly order counts and revenue (per base currency, canceled orders excluded) for the recent window; the last row is the current partial hour.',
                $this->hourlyBuckets(),
                keyName: 'row'
            ),
            'baseline' => $this->section->facts(
                'baseline',
                'Order Rate vs Baseline',
                'Current order count for the last '.self::ORDER_RATE_WINDOW_HOURS.'h compared with the '
                . 'same hours on each of the last four weeks.',
                $this->orderRateBaselineFields()
            ),
            'stuck_pending_payment' => $this->section->facts(
                'stuck_pending_payment',
                'Stuck Pending Payments',
                'Orders stuck in pending_payment - created between '
                . self::STUCK_PENDING_PAYMENT_MAX_AGE_HOURS.'h and '
                . self::STUCK_PENDING_PAYMENT_MIN_AGE_MINUTES.'m ago, suggesting the gateway redirect '
                . 'never came back.',
                $this->stuckPendingPaymentFields()
            ),
            'stuck_payments' => $this->section->table(
                'stuck_payments',
                'Stuck Payments By Method',
                'Stuck pending_payment orders grouped by payment method.',
                $this->stuckPaymentsByMethodRows(),
                keyName: 'method'
            ),
        ];
    }

    /**
     * Alertable metrics for the sales reporter: lifetime order and quote-with-items growth,
     * order rate vs its weekly baseline, and the stuck-pending-payment percentage.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_ORDERS_LIFETIME,
                'Orders (Lifetime)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_LT,
                // "None at all": a quiet store legitimately takes only a handful of orders in six hours.
                1,
                360,
                null,
                description: 'No new orders in the last {window} (counted as growth of the highest order id).',
                impact: 'Checkout may be broken, or the store is not getting traffic.'
            ),
            new MetricDefinition(
                self::METRIC_QUOTES_WITH_ITEMS_LIFETIME,
                'Quotes With Items (Lifetime)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_LT,
                1,
                360,
                null,
                description: 'No new baskets in the last {window} (counted as growth of the highest quote id).',
                impact: 'Customers may not be able to add products to the basket.'
            ),
            new MetricDefinition(
                self::METRIC_ORDERS_VS_BASELINE_PERCENT,
                'Order Rate vs Baseline (%)',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                50,
                180,
                null,
                description: 'Orders in the last 3 hours are at {value} of the usual rate for this time of '
                    . 'week (alert below {threshold}).',
                impact: 'Checkout, payment or traffic problem.',
                criticalThreshold: 25,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_STUCK_PENDING_PAYMENT_PERCENT,
                'Stuck Pending Payments (%)',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                20,
                180,
                null,
                description: '{value} of recent orders are stuck in pending_payment (alert above {threshold}).',
                impact: 'Payment gateway redirect is failing to return, losing orders.',
                criticalThreshold: 50,
                unit: Unit::PERCENT
            ),
        ];
    }

    /**
     * Highest entity_id in the table (0 when empty) - a primary-key lookup, never a scan, and
     * monotonic unlike COUNT(*) on a table that gets purged.
     *
     * Deliberately does not catch failures here - a query failure should surface as this
     * whole reporter's block becoming {"error": ...} via ReporterPool's own error isolation,
     * not silently report "0" as if that were a real (and highly alertable) order count.
     *
     * @param string $table
     */
    private function maxEntityId(string $table): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);

        $select = $connection->select()
            ->from($tableName, ['max_id' => new Expression('MAX(entity_id)')]);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Builds the recent hourly breakdown, zero-filled for hours with no orders. The window
     * starts on an hour boundary so the first bucket is a full hour; the last bucket is the
     * current, still-partial hour.
     *
     * When revenue is enabled there is one row per (hour, base currency): count is every order
     * in that hour and currency, revenue excludes canceled orders. When disabled there is one
     * row per hour with the count only. Zero-filled hours carry an empty currency.
     *
     * @return list<ArrayField>
     */
    private function hourlyBuckets(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        $withRevenue = $this->config->isSendRevenueEnabled();

        $now = $this->clock->now();
        $rawStart = $now->modify(sprintf('-%d hours', self::HOURLY_WINDOW_HOURS));
        $windowStart = $rawStart->setTime((int) $rawStart->format('H'), 0, 0);

        $columns = [
            'hour_bucket' => new Expression("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')"),
            'cnt' => new Expression('COUNT(*)'),
        ];
        $groupBy = ['hour_bucket'];
        if ($withRevenue) {
            $canceled = $connection->quote(Order::STATE_CANCELED);
            $columns['currency'] = new Expression('base_currency_code');
            $columns['revenue'] = new Expression(
                "SUM(CASE WHEN state <> {$canceled} THEN base_grand_total ELSE 0 END)"
            );
            $groupBy[] = 'base_currency_code';
        }

        $select = $connection->select()
            ->from($table, $columns)
            ->where('created_at >= ?', $windowStart->format('Y-m-d H:i:s'))
            ->group($groupBy);

        $found = [];
        foreach ($connection->fetchAll($select) as $row) {
            $found[(string) $row['hour_bucket']][] = [
                'count' => (int) $row['cnt'],
                'currency' => (string) ($row['currency'] ?? ''),
                'revenue' => round((float) ($row['revenue'] ?? 0), 2),
            ];
        }

        $result = [];
        $cursor = $windowStart;
        while ($cursor <= $now) {
            $hourKey = $cursor->format('Y-m-d H:00:00');
            $entries = $found[$hourKey] ?? [['count' => 0, 'currency' => '', 'revenue' => 0.0]];
            foreach ($entries as $entry) {
                $columnsOut = ['hour' => $this->field->datetime('Hour', $hourKey)];
                if ($withRevenue) {
                    $columnsOut['currency'] = $this->field->varchar('Currency', $entry['currency']);
                }
                $columnsOut['count'] = $this->field->number('Count', $entry['count']);
                if ($withRevenue) {
                    $columnsOut['revenue'] = $this->field->number('Revenue', $entry['revenue']);
                }
                $result[] = $this->field->array($hourKey.'|'.$entry['currency'], $columnsOut);
            }
            $cursor = $cursor->modify('+1 hour');
        }

        return $result;
    }

    /**
     * SUM(CASE WHEN created_at BETWEEN $start AND $end THEN 1 ELSE 0 END) as a query column,
     * with the boundaries safely quoted into the raw SQL rather than concatenated.
     *
     * @param AdapterInterface $connection
     * @param \DateTimeImmutable $start
     * @param \DateTimeImmutable $end
     */
    private function windowCountExpression(
        AdapterInterface $connection,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): Expression {
        return new Expression(sprintf(
            'SUM(CASE WHEN created_at BETWEEN %s AND %s THEN 1 ELSE 0 END)',
            $connection->quote($start->format('Y-m-d H:i:s')),
            $connection->quote($end->format('Y-m-d H:i:s'))
        ));
    }

    /**
     * Current order count for the last ORDER_RATE_WINDOW_HOURS, plus the same-length window
     * at each of BASELINE_WEEKS_AGO, all via one aggregate query over sales_order (indexed on
     * created_at) bounded to the earliest window needed.
     *
     * @return array{current: int, weekly: array<int, int>}
     */
    private function orderRateWindows(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        $now = $this->clock->now();

        $currentStart = $now->modify(sprintf('-%d hours', self::ORDER_RATE_WINDOW_HOURS));

        $columns = ['current_count' => $this->windowCountExpression($connection, $currentStart, $now)];

        $weeklyColumnNames = [];
        foreach (self::BASELINE_WEEKS_AGO as $daysAgo) {
            $weekEnd = $now->modify(sprintf('-%d days', $daysAgo));
            $weekStart = $weekEnd->modify(sprintf('-%d hours', self::ORDER_RATE_WINDOW_HOURS));
            $columnName = 'week_'.$daysAgo;
            $weeklyColumnNames[$daysAgo] = $columnName;
            $columns[$columnName] = $this->windowCountExpression($connection, $weekStart, $weekEnd);
        }

        // Earliest boundary across all five windows, so this stays one aggregate query while
        // still pruning the scan via the indexed created_at column.
        $overallStart = $now
            ->modify(sprintf('-%d days', max(self::BASELINE_WEEKS_AGO)))
            ->modify(sprintf('-%d hours', self::ORDER_RATE_WINDOW_HOURS));

        $select = $connection->select()
            ->from($table, $columns)
            ->where('created_at >= ?', $overallStart->format('Y-m-d H:i:s'));

        $row = (array) $connection->fetchRow($select);

        $weekly = [];
        foreach ($weeklyColumnNames as $daysAgo => $columnName) {
            $weekly[$daysAgo] = (int) ($row[$columnName] ?? 0);
        }

        return [
            'current' => (int) ($row['current_count'] ?? 0),
            'weekly' => $weekly,
        ];
    }

    /**
     * Builds the "baseline" facts section fields: current order rate, its weekly baseline
     * average, each underlying weekly count, and orders_vs_baseline_percent - only trackable
     * once the baseline average clears BASELINE_MIN_ORDERS (see that constant's docblock).
     *
     * @return array<string, \StackNuts\StackGauge\Api\Field\FieldInterface>
     */
    private function orderRateBaselineFields(): array
    {
        $windows = $this->orderRateWindows();
        $current = $windows['current'];
        $weekly = $windows['weekly'];

        $baselineAverage = round(array_sum($weekly) / count($weekly), 1);
        $baselineSufficient = $baselineAverage >= self::BASELINE_MIN_ORDERS;
        $percent = $baselineAverage > 0.0 ? round($current / $baselineAverage * 100, 1) : 0.0;

        $percentField = $baselineSufficient
            ? $this->field->trackableNumber(
                'Orders vs Baseline',
                $percent,
                self::METRIC_ORDERS_VS_BASELINE_PERCENT,
                MetricDefinition::AGGREGATION_LATEST,
                unit: Unit::PERCENT
            )
            : $this->field->number('Orders vs Baseline', $percent, unit: Unit::PERCENT);

        return [
            'current_count' => $this->field->number('Current Count', $current),
            'baseline_average' => $this->field->number('Baseline Average', $baselineAverage),
            'baseline_count_7d' => $this->field->number('Baseline Count (7d Ago)', $weekly[7]),
            'baseline_count_14d' => $this->field->number('Baseline Count (14d Ago)', $weekly[14]),
            'baseline_count_21d' => $this->field->number('Baseline Count (21d Ago)', $weekly[21]),
            'baseline_count_28d' => $this->field->number('Baseline Count (28d Ago)', $weekly[28]),
            'orders_vs_baseline_percent' => $percentField,
            'baseline_sufficient' => $this->field->bool('Baseline Sufficient', $baselineSufficient),
        ];
    }

    /**
     * Total orders and pending_payment orders in the stuck-payment window, plus the oldest
     * stuck order's created_at, via one aggregate query over sales_order (indexed on
     * created_at and state).
     *
     * @return array{total: int, stuck: int, oldestCreatedAt: string|null}
     */
    private function stuckPendingPaymentWindow(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        $now = $this->clock->now();

        $windowStart = $now->modify(sprintf('-%d hours', self::STUCK_PENDING_PAYMENT_MAX_AGE_HOURS));
        $windowEnd = $now->modify(sprintf('-%d minutes', self::STUCK_PENDING_PAYMENT_MIN_AGE_MINUTES));
        $pendingPayment = $connection->quote(Order::STATE_PENDING_PAYMENT);

        $select = $connection->select()
            ->from($table, [
                'total_count' => new Expression('COUNT(*)'),
                'stuck_count' => new Expression("SUM(CASE WHEN state = {$pendingPayment} THEN 1 ELSE 0 END)"),
                'oldest_stuck_created_at' => new Expression(
                    "MIN(CASE WHEN state = {$pendingPayment} THEN created_at ELSE NULL END)"
                ),
            ])
            ->where('created_at >= ?', $windowStart->format('Y-m-d H:i:s'))
            ->where('created_at <= ?', $windowEnd->format('Y-m-d H:i:s'));

        $row = (array) $connection->fetchRow($select);

        return [
            'total' => (int) ($row['total_count'] ?? 0),
            'stuck' => (int) ($row['stuck_count'] ?? 0),
            'oldestCreatedAt' => $row['oldest_stuck_created_at'] ?? null,
        ];
    }

    /**
     * Builds the "stuck_pending_payment" facts section fields: stuck count, total orders in
     * the same window, stuck_pending_payment_percent, and the oldest stuck order's age -
     * stuck_pending_payment_percent is only trackable once the window clears
     * STUCK_PENDING_PAYMENT_MIN_ORDERS (see that constant's docblock).
     *
     * @return array<string, \StackNuts\StackGauge\Api\Field\FieldInterface>
     */
    private function stuckPendingPaymentFields(): array
    {
        $window = $this->stuckPendingPaymentWindow();
        $total = $window['total'];
        $stuck = $window['stuck'];
        $oldestCreatedAt = $window['oldestCreatedAt'];

        $stuckPercent = $total > 0 ? round($stuck / $total * 100, 1) : 0.0;
        $sufficient = $total >= self::STUCK_PENDING_PAYMENT_MIN_ORDERS;

        $percentField = $sufficient
            ? $this->field->trackableNumber(
                'Stuck Pending Payments',
                $stuckPercent,
                self::METRIC_STUCK_PENDING_PAYMENT_PERCENT,
                MetricDefinition::AGGREGATION_LATEST,
                unit: Unit::PERCENT
            )
            : $this->field->number('Stuck Pending Payments', $stuckPercent, unit: Unit::PERCENT);

        $oldestAgeMinutes = 0;
        if ($oldestCreatedAt !== null) {
            $oldestDate = new \DateTimeImmutable((string) $oldestCreatedAt, new \DateTimeZone('UTC'));
            $oldestAgeMinutes = (int) round(($this->clock->now()->getTimestamp() - $oldestDate->getTimestamp()) / 60);
        }

        return [
            'stuck_count' => $this->field->number('Stuck Count', $stuck),
            'total_orders_in_window' => $this->field->number('Total Orders In Window', $total),
            'stuck_pending_payment_percent' => $percentField,
            'oldest_stuck_order_age_minutes' => $this->field->number(
                'Oldest Stuck Order Age',
                $oldestAgeMinutes,
                unit: Unit::MINUTES
            ),
        ];
    }

    /**
     * Builds one row per payment method with a stuck pending_payment order in the window
     * (see STUCK_PENDING_PAYMENT_MAX_AGE_HOURS/MIN_AGE_MINUTES), via a join against
     * sales_order_payment grouped by method - stuck count and oldest created_at only, no
     * customer data, increment IDs, or amounts.
     *
     * @return list<ArrayField>
     */
    private function stuckPaymentsByMethodRows(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $paymentTable = $this->resourceConnection->getTableName('sales_order_payment');
        $now = $this->clock->now();

        $windowStart = $now->modify(sprintf('-%d hours', self::STUCK_PENDING_PAYMENT_MAX_AGE_HOURS));
        $windowEnd = $now->modify(sprintf('-%d minutes', self::STUCK_PENDING_PAYMENT_MIN_AGE_MINUTES));

        $select = $connection->select()
            ->from(['o' => $orderTable], [])
            ->joinInner(['p' => $paymentTable], 'p.parent_id = o.entity_id', ['method'])
            ->where('o.state = ?', Order::STATE_PENDING_PAYMENT)
            ->where('o.created_at >= ?', $windowStart->format('Y-m-d H:i:s'))
            ->where('o.created_at <= ?', $windowEnd->format('Y-m-d H:i:s'))
            ->columns([
                'stuck_count' => new Expression('COUNT(*)'),
                'oldest_created_at' => new Expression('MIN(o.created_at)'),
            ])
            ->group('p.method')
            ->order('p.method ASC');

        $rows = $connection->fetchAll($select);

        return array_map(function (array $row): ArrayField {
            $method = (string) $row['method'];

            return $this->field->array($method, [
                'method' => $this->field->varchar('Payment Method', $method),
                'stuck_count' => $this->field->number('Stuck Count', (int) $row['stuck_count']),
                'oldest_created_at' => $this->field->datetime(
                    'Oldest Created At',
                    (string) $row['oldest_created_at']
                ),
            ]);
        }, $rows);
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }
}
