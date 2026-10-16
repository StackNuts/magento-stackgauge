<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\SalesRule\Api\Data\RuleInterface;
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
use StackNuts\StackGauge\Model\Util\Clock;

/**
 * Active cart price rules (what agencies mean by "coupons"), plus a lifetime redemption total
 * across every rule ever created. Rolled up at the rule level, using salesrule.times_used
 * (Magento's own rule-level counter) rather than summing salesrule_coupon rows.
 *
 * coupon_type alone does not imply a rule has exactly one code - a SPECIFIC-type rule can
 * carry thousands of auto-generated salesrule_coupon rows via the separate
 * use_auto_generation flag, so code counts are aggregated per rule_id rather than assumed to
 * be 1 (see activeRuleRows()). Only the number of codes is reported, never a code.
 */
class CouponsReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private ?string $linkField = null;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_TOTAL_REDEMPTIONS = 'coupons.total_redemptions';

    private const COUPON_TYPE_LABELS = [
        Rule::COUPON_TYPE_NO_COUPON => 'No Coupon',
        Rule::COUPON_TYPE_SPECIFIC => 'Specific Coupon',
        Rule::COUPON_TYPE_AUTO => 'Auto-Generated',
    ];

    /**
     * @param ResourceConnection $resourceConnection
     * @param Field $field
     * @param Section $section
     * @param MetadataPool $metadataPool
     * @param Clock $clock
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Field $field,
        private readonly Section $section,
        private readonly MetadataPool $metadataPool,
        private readonly Clock $clock
    ) {
    }

    /**
     * Payload key for the coupons reporter.
     */
    public function getName(): string
    {
        return 'coupons';
    }

    /**
     * Human-readable label for the coupons reporter block.
     */
    public function getLabel(): string
    {
        return 'Coupons';
    }

    /**
     * One-line summary of what the coupons reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Active cart price rules and their lifetime redemption counts.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the lifetime redemption total across every cart price rule via raw SQL, plus a table of active rules.
     */
    public function getStatus(): array
    {
        return [
            'general' => $this->section->facts(
                'general',
                'General',
                'Lifetime redemption count across every cart price rule ever created, active or not.',
                [
                    'total_redemptions' => $this->field->trackableNumber(
                        'Total Redemptions (All Rules)',
                        $this->totalRedemptionsAllRules(),
                        self::METRIC_TOTAL_REDEMPTIONS,
                        MetricDefinition::AGGREGATION_DELTA
                    ),
                ]
            ),
            'active_rules' => $this->section->table(
                'active_rules',
                'Active Coupons',
                'Cart price rules currently marked active and not yet expired, with their coupon '
                . 'code count and lifetime usage. Codes themselves are never sent.',
                $this->activeRuleRows(),
                keyName: 'rule_id'
            ),
        ];
    }

    /**
     * Alertable metric for the coupons reporter: growth in total redemptions across all rules.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_TOTAL_REDEMPTIONS,
                'Coupons: Total Redemptions (All Rules)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                100,
                10080,
                null,
                description: 'Coupons were redeemed {value} times in the last {window}, above {threshold}.',
                impact: 'Possible coupon abuse or a leaked code.'
            ),
        ];
    }

    /**
     * Summed across every current rule row regardless of is_active/date range - restricting to
     * the active subset would make this drop whenever a rule expires or is disabled, which
     * AGGREGATION_DELTA (max - min over the window) would misread as "no growth". On staging
     * editions only the current row of each rule counts, or every scheduled version would add
     * its times_used again.
     */
    private function totalRedemptionsAllRules(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('salesrule');

        $select = $connection->select()->from($table, ['total' => new Expression('SUM(times_used)')]);
        $this->restrictToCurrentRows($select);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Builds one row per active, unexpired cart price rule (capped at TableSection's row
     * limit, plus one so it can flag truncation), with the number of codes per rule.
     *
     * Coupon codes and rule descriptions are deliberately not sent: a code is a working
     * credential and a description is free text an admin may have put anything in.
     *
     * @return list<ArrayField>
     */
    private function activeRuleRows(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $ruleTable = $this->resourceConnection->getTableName('salesrule');
        $couponTable = $this->resourceConnection->getTableName('salesrule_coupon');
        $linkField = $this->getLinkField();

        $select = $connection->select()
            ->from(['r' => $ruleTable], [
                'link_id' => $linkField, 'rule_id', 'name', 'coupon_type', 'times_used', 'from_date', 'to_date',
            ])
            ->where('r.is_active = ?', 1)
            ->where('(r.to_date IS NULL OR r.to_date >= ?)', $this->clock->now()->format('Y-m-d'))
            ->order('r.rule_id ASC')
            ->limit(ArrayField::MAX_ITEMS + 1);
        $this->restrictToCurrentRows($select, 'r.');

        $rows = $connection->fetchAll($select);

        // Counted only for the rules being reported, COUNT(*) on the rule_id index - never
        // MIN(code), which forces a full scan of a table that can hold millions of generated codes.
        $couponCounts = [];
        if ($rows !== []) {
            $linkIds = array_map(static fn (array $row): int => (int) $row['link_id'], $rows);
            $countSelect = $connection->select()
                ->from($couponTable, ['rule_id', 'coupon_count' => new Expression('COUNT(*)')])
                ->where('rule_id IN (?)', $linkIds)
                ->group('rule_id');
            $couponCounts = $connection->fetchPairs($countSelect);
        }

        return array_map(function (array $row) use ($couponCounts): ArrayField {
            $couponType = (int) $row['coupon_type'];

            return $this->field->array((string) $row['name'], [
                'rule_id' => $this->field->number('Rule ID', (int) $row['rule_id']),
                'name' => $this->field->varchar('Name', (string) $row['name']),
                'coupon_type' => $this->field->varchar(
                    'Coupon Type',
                    self::COUPON_TYPE_LABELS[$couponType] ?? 'Unknown'
                ),
                'coupon_count' => $this->field->number(
                    'Coupon Codes',
                    (int) ($couponCounts[$row['link_id']] ?? 0)
                ),
                // Plain store-local dates as Magento stores them - they are not instants, so
                // they are not dressed up as midnight UTC.
                'from_date' => $this->field->varchar('Start Date', (string) ($row['from_date'] ?? '')),
                'to_date' => $this->field->varchar('End Date', (string) ($row['to_date'] ?? '')),
                'times_used' => $this->field->number('Times Used', (int) $row['times_used']),
            ]);
        }, $rows);
    }

    /**
     * The salesrule link field: rule_id on Open Source, row_id on Adobe Commerce staging.
     */
    private function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = (string) $this->metadataPool->getMetadata(RuleInterface::class)->getLinkField();
        }

        return $this->linkField;
    }

    /**
     * Restricts to current rule rows on staging editions; a no-op on Open Source.
     *
     * @param Select $select
     * @param string $alias Table alias prefix including the dot, or '' for none.
     */
    private function restrictToCurrentRows(Select $select, string $alias = ''): void
    {
        if ($this->getLinkField() === 'rule_id') {
            return;
        }

        $select->where($alias . 'created_in <= UNIX_TIMESTAMP() AND ' . $alias . 'updated_in > UNIX_TIMESTAMP()');
    }
}
