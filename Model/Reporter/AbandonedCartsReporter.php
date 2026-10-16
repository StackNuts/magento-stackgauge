<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\Clock;

/**
 * Active quotes with items that have gone idle, read with one raw aggregate query over
 * `quote` (a full scan - no quote index leads with is_active or updated_at - but it runs
 * daily and returns one row).
 *
 * "Abandoned" means no activity for 24h. Magento only purges quotes after ~30 days, so the
 * total of everything idle for 24h+ is a large, always-present backlog; that total is shown
 * but not alerted on. The tracked metric (`abandoned_carts.count`) is the number of carts that
 * went idle during the last day (idle for 24-48h): a per-day figure that stays bounded and is
 * comparable run to run, so a plain threshold works.
 */
class AbandonedCartsReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.1';
    private const METRIC_COUNT = 'abandoned_carts.count';
    private const SAMPLE_LIMIT = 5;
    private const HOURS_OLD = 24;

    /**
     * @param ResourceConnection $resourceConnection
     * @param Clock $clock
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the abandoned-carts reporter.
     */
    public function getName(): string
    {
        return 'abandoned_carts';
    }

    /**
     * Human-readable label for the abandoned-carts reporter block.
     */
    public function getLabel(): string
    {
        return 'Abandoned Carts';
    }

    /**
     * One-line summary of the abandoned-carts reporter, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Carts with items that went idle in the last day, plus the total backlog idle for over 24h.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports how many active carts with items went idle in the last day (the tracked
     * count), the total idle for 24h+, and up to five of the newest such quote IDs.
     */
    public function getStatus(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('quote');

        $now = $this->clock->now();
        $idleSince = $now->modify(sprintf('-%d hours', self::HOURS_OLD))->format('Y-m-d H:i:s');
        $windowStart = $now->modify(sprintf('-%d hours', self::HOURS_OLD * 2))->format('Y-m-d H:i:s');

        $totals = (array) $connection->fetchRow(
            $connection->select()
                ->from($table, [
                    'total' => new Expression('COUNT(*)'),
                    'recent' => new Expression(
                        'SUM(CASE WHEN updated_at >= ' . $connection->quote($windowStart) . ' THEN 1 ELSE 0 END)'
                    ),
                ])
                ->where('is_active = 1')
                ->where('items_count > 0')
                ->where('updated_at < ?', $idleSince)
        );

        $sampleRows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['entity_id', 'updated_at'])
                ->where('is_active = 1')
                ->where('items_count > 0')
                ->where('updated_at < ?', $idleSince)
                ->where('updated_at >= ?', $windowStart)
                ->order(['updated_at DESC', 'entity_id DESC'])
                ->limit(self::SAMPLE_LIMIT)
        );

        $sampleFields = [];
        foreach ($sampleRows as $row) {
            $quoteId = (string) $row['entity_id'];
            $sampleFields[] = $this->field->array($quoteId, [
                'quote_id' => $this->field->varchar('Quote ID', $quoteId),
                'updated_at' => $this->field->datetime('Last Activity', (string) $row['updated_at']),
            ]);
        }

        return [
            'general' => $this->section->facts('general', 'General', '', [
                'count' => $this->field->trackableNumber(
                    'Went Idle In Last Day',
                    (int) ($totals['recent'] ?? 0),
                    self::METRIC_COUNT,
                    MetricDefinition::AGGREGATION_LATEST
                ),
                'total_idle' => $this->field->number('Total Idle Over 24h', (int) ($totals['total'] ?? 0)),
            ]),
            'samples' => $this->section->table(
                'samples',
                'Samples',
                'Most recently abandoned cart IDs.',
                $sampleFields,
                keyName: 'quote_id'
            ),
        ];
    }

    /**
     * Alertable metric for the abandoned-carts reporter: carts that went idle in the last day.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // LATEST against a plain threshold, on the per-day "went idle" figure rather than
            // the total backlog (permanently large: Magento keeps idle quotes ~30 days) or a
            // DELTA of it (which measures how much a fluctuating number moves, not growth, and
            // fired constantly on big stores). 1000/day is above normal on a typical agency
            // client store, so it flags an unusual spike (checkout friction, payment failures);
            // large stores should raise it from the rule editor.
            new MetricDefinition(
                self::METRIC_COUNT,
                'Abandoned Carts: Went Idle In Last Day',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                1000,
                1440,
                null,
                description: '{value} carts with items went idle in the last day, above {threshold}.',
                impact: 'Possible checkout friction or payment problems.'
            ),
        ];
    }
}
