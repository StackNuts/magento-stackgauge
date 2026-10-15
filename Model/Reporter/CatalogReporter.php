<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;

/**
 * Enabled product count - a simple "the catalog hasn't been wiped" signal. Uses the product
 * collection rather than hand-rolled SQL since "status" is an EAV attribute, not a flat
 * column; getSize() alone still keeps this a single COUNT(*) query.
 */
class CatalogReporter implements ReporterInterface, MetricCatalogInterface, DeclaresCadenceInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_PRODUCTS_ENABLED = 'catalog.products_enabled_count';

    /**
     * @param ProductCollectionFactory $productCollectionFactory
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
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
     * Reports the count of enabled products via a single COUNT(*) query on the product collection.
     */
    public function getStatus(): array
    {
        $enabledCount = $this->productCollectionFactory->create()
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->getSize();

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
}
