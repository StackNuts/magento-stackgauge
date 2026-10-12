<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;

class ProductHealthReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_MISSING_IMAGE = 'product_health.missing_image_count';
    private const METRIC_NO_PRICE = 'product_health.no_price_count';

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
     * Payload key for the product-health reporter.
     */
    public function getName(): string
    {
        return 'product_health';
    }

    /**
     * Human-readable label for the product-health reporter block.
     */
    public function getLabel(): string
    {
        return 'Product Health';
    }

    /**
     * One-line summary of what the product-health reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Counts of common product data issues.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the count of enabled products missing an image and the count priced at zero or below.
     */
    public function getStatus(): array
    {
        $missingImage = $this->productCollectionFactory->create()
            ->addAttributeToFilter('image', ['eq' => 'no_selection'])
            ->getSize();

        $noPrice = $this->productCollectionFactory->create()
            ->addAttributeToFilter('price', ['lte' => 0])
            ->getSize();

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'missing_image' => $this->field->trackableNumber(
                'Missing Image',
                $missingImage,
                self::METRIC_MISSING_IMAGE,
                MetricDefinition::AGGREGATION_LATEST
            ),
            'no_price' => $this->field->trackableNumber(
                'No Price',
                $noPrice,
                self::METRIC_NO_PRICE,
                MetricDefinition::AGGREGATION_LATEST
            ),
        ])];
    }

    /**
     * Alertable metrics for the product-health reporter: missing-image and no-price product counts.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // Window comfortably outlives the ~24h gap between daily-cadence samples.
            new MetricDefinition(
                self::METRIC_MISSING_IMAGE,
                'Missing Image',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                20,
                1500,
                null,
                description: '{value} products have no image, above the limit of {threshold}.',
                impact: 'Customers see broken or unappealing product pages.'
            ),
            new MetricDefinition(
                self::METRIC_NO_PRICE,
                'No Price',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                5,
                1500,
                null,
                description: '{value} products have no price, above the limit of {threshold}.',
                impact: 'Customers cannot buy products without a price.'
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
