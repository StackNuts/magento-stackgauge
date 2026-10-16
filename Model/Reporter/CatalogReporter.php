<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\EnabledProductSql;

/**
 * Enabled product count - a simple "the catalog hasn't been wiped" signal. One COUNT(*) over
 * the EAV tables at default scope (see Util\EnabledProductSql) - deliberately not a product
 * collection, which may be served from the flat tables in the cron area and miss products not
 * yet reindexed there. A product disabled only on one store view still counts as enabled.
 */
class CatalogReporter implements ReporterInterface, MetricCatalogInterface, DeclaresCadenceInterface, DeclaresSectionInterface, DeclaresSlotInterface, DeclaresSubjectInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_PRODUCTS_ENABLED = 'catalog.products_enabled_count';

    /**
     * @param ResourceConnection $resourceConnection
     * @param EnabledProductSql $enabledProductSql
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EnabledProductSql $enabledProductSql,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the catalog reporter.
     */
    public function getName(): string
    {
        return 'catalog';
    }

    /**
     * Human-readable label for the catalog reporter block.
     */
    public function getLabel(): string
    {
        return 'Catalog';
    }

    /**
     * One-line summary of what the catalog reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Enabled product count.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the count of enabled (default-scope status) products via a single COUNT(*) query.
     */
    public function getStatus(): array
    {
        $enabledCount = (int) $this->resourceConnection->getConnection()->fetchOne(
            'SELECT COUNT(*) ' . $this->enabledProductSql->from()
        );

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'products_enabled_count' => $this->field->trackableNumber(
                'Products Enabled',
                $enabledCount,
                self::METRIC_PRODUCTS_ENABLED,
                MetricDefinition::AGGREGATION_LATEST
            ),
        ])];
    }

    /**
     * Alertable metric for the catalog reporter: enabled product count dropping to zero.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // A daily-cadence metric's default window must comfortably outlive the ~24h gap
            // between samples, or the "latest" sample ages out of the window before the next
            // one arrives and the rule spuriously sees no data at all.
            new MetricDefinition(
                self::METRIC_PRODUCTS_ENABLED,
                'Products Enabled',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                1,
                1500,
                null,
                description: 'Only {value} products are enabled, below the minimum of {threshold}.',
                impact: 'The catalogue may have been disabled or wiped by accident.',
                severity: MetricDefinition::SEVERITY_CRITICAL
            ),
        ];
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'catalog';
    }
}
