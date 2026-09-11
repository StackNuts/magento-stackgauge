<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\SalesRule\Model\Rule;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;

/**
 * Active cart price rules (what agencies mean by "coupons"), plus a lifetime redemption total
 * across every rule ever created. Rolled up at the rule level, using salesrule.times_used
 * (Magento's own rule-level counter) rather than summing salesrule_coupon rows.
 *
 * coupon_type alone does not imply a rule has exactly one code - a SPECIFIC-type rule can
 * carry thousands of auto-generated salesrule_coupon rows via the separate
 * use_auto_generation flag, so code counts are aggregated per rule_id rather than assumed to
 * be 1 (see activeRuleRows()).
 */
final class CouponsReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_TOTAL_REDEMPTIONS = 'coupons.total_redemptions';

    private const COUPON_TYPE_LABELS = [
        Rule::COUPON_TYPE_NO_COUPON => 'No Coupon',
        Rule::COUPON_TYPE_SPECIFIC => 'Specific Coupon',
        Rule::COUPON_TYPE_AUTO => 'Auto-Generated',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'coupons';
    }

    public function getLabel(): string
    {
        return 'Coupons';
    }

    public function getDescription(): string
    {
        return 'Active cart price rules and their lifetime redemption counts.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        return [
            'general' => Section::facts(
                'general',
                'General',
                'Lifetime redemption count across every cart price rule ever created, active or not.',
                [
                    'total_redemptions' => Field::trackableNumber(
                        'Total Redemptions (All Rules)',
                        $this->totalRedemptionsAllRules(),
                        self::METRIC_TOTAL_REDEMPTIONS,
                        MetricDefinition::AGGREGATION_DELTA
                    ),
                ]
            ),
            'active_rules' => Section::table(
                'active_rules',
                'Active Coupons',
                'Cart price rules currently marked active, with their fixed code (Specific-Coupon '
                . 'rules only) and lifetime usage.',
                $this->activeRuleRows(),
                keyName: 'rule_id'
            ),
        ];
    }

    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_TOTAL_REDEMPTIONS,
                'Coupons: Total Redemptions (All Rules)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                100,
                10080
            ),
        ];
    }

    /**
     * Summed across every rule regardless of is_active/date range - restricting to the
     * active subset would make this drop whenever a rule expires or is disabled, which
     * AGGREGATION_DELTA (max - min over the window) would misread as "no growth".
     */
    private function totalRedemptionsAllRules(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('salesrule');

        $select = $connection->select()->from($table, ['total' => new Expression('SUM(times_used)')]);

        return (int) $connection->fetchOne($select);
    }

    /**
     * @return list<ArrayField>
     */
    private function activeRuleRows(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $ruleTable = $this->resourceConnection->getTableName('salesrule');
        $couponTable = $this->resourceConnection->getTableName('salesrule_coupon');

        // Aggregated to one row per rule_id before the join - joining salesrule_coupon
        // directly on rule_id would fan out into one row per code (see class docblock).
        $couponAgg = $connection->select()
            ->from($couponTable, [
                'rule_id',
                'coupon_count' => new Expression('COUNT(*)'),
                'code' => new Expression('MIN(code)'),
            ])
            ->group('rule_id');

        $select = $connection->select()
            ->from(['r' => $ruleTable], [
                'rule_id', 'name', 'description', 'coupon_type', 'times_used', 'from_date', 'to_date',
            ])
            ->joinLeft(['c' => $couponAgg], 'c.rule_id = r.rule_id', ['coupon_count', 'code'])
            ->where('r.is_active = ?', 1)
            ->order('r.rule_id ASC');

        $rows = $connection->fetchAll($select);

        return array_map(function (array $row): ArrayField {
            $couponType = (int) $row['coupon_type'];
            $couponCount = (int) ($row['coupon_count'] ?? 0);

            return Field::array((string) $row['name'], [
                'rule_id' => Field::number('Rule ID', (int) $row['rule_id']),
                'name' => Field::varchar('Name', (string) $row['name']),
                'description' => Field::varchar('Description', (string) ($row['description'] ?? '')),
                'coupon_type' => Field::varchar('Coupon Type', self::COUPON_TYPE_LABELS[$couponType] ?? 'Unknown'),
                // Only meaningful as a single "the code" when the rule has exactly one - a
                // batch of generated codes has no one fixed code to show, just a count.
                'code' => Field::varchar('Code', $couponCount === 1 ? (string) ($row['code'] ?? '') : ''),
                'coupon_count' => Field::number('Coupon Codes', $couponCount),
                'from_date' => Field::datetime('Start Date', $this->toUtcDateTime($row['from_date'])),
                'to_date' => Field::datetime('End Date', $this->toUtcDateTime($row['to_date'])),
                'times_used' => Field::number('Times Used', (int) $row['times_used']),
            ]);
        }, $rows);
    }

    /**
     * from_date/to_date are plain DATE columns (no time component) - stretched to
     * DateTimeField's "Y-m-d H:i:s" shape at midnight UTC. Null (to_date only, meaning "never
     * expires") becomes '', DateTimeField's own convention for "never".
     */
    private function toUtcDateTime(?string $date): string
    {
        return $date ? $date . ' 00:00:00' : '';
    }
}
