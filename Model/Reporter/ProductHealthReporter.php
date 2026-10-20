<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

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
use StackNuts\StackGauge\Model\Reporter\ProductHealth\ProductHealthQueries as Q;

/**
 * Catalog data-quality checks (images, prices, descriptions, website/category assignment,
 * orphaned and unbuyable composite products, URL rewrites, duplicate URL keys), all read with
 * raw SQL by ProductHealthQueries. Missing-image, no-price, no-website and unbuyable-composite
 * counts are alertable metrics; the rest are display-only. Configurable out-of-stock reads the default
 * stock only (not custom MSI stocks), so multi-stock MSI stores may see false hits.
 */
class ProductHealthReporter implements
    ReporterInterface,
    DeclaresCadenceInterface,
    MetricCatalogInterface,
    DeclaresSectionInterface,
    DeclaresSlotInterface,
    DeclaresSubjectInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '2.0';
    private const METRIC_MISSING_IMAGE = 'product_health.missing_image_count';
    private const METRIC_NO_WEBSITE = 'product_health.no_website_count';
    private const METRIC_COMPOSITE_UNBUYABLE = 'product_health.composite_unbuyable_count';

    /**
     * Check => [count label, samples title, trackable metric key or null, has samples table].
     */
    private const CHECKS = [
        Q::CHECK_MISSING_IMAGE => ['Missing Image', 'Products missing an image', self::METRIC_MISSING_IMAGE, true],
        // Display-only: it also counts not-visible children and free items priced at zero, and the products that
        // actually can't be bought are already alerted on by pricing.zero_final_price_count.
        Q::CHECK_NO_PRICE => ['No Price', 'Products without a price', null, true],
        Q::CHECK_MISSING_DESCRIPTION => ['Missing Description', 'Products missing a description', null, true],
        Q::CHECK_MISSING_META_DESCRIPTION => ['Missing Meta Description', '', null, false],
        Q::CHECK_NO_WEBSITE => ['No Website', 'Enabled products not in any website', self::METRIC_NO_WEBSITE, true],
        Q::CHECK_NO_CATEGORY => ['No Category', 'Products in no category', null, true],
        Q::CHECK_ORPHANED_NOT_VISIBLE => [
            'Orphaned Not Visible', 'Not-visible products that are no one\'s child', null, true,
        ],
        Q::CHECK_COMPOSITE_UNBUYABLE => [
            'Unbuyable Composite Products', 'Composite products with nothing purchasable',
            self::METRIC_COMPOSITE_UNBUYABLE, true,
        ],
        Q::CHECK_URL_REWRITE_MISSING => [
            'Missing URL Rewrite', 'Products missing a URL rewrite in some store view', null, true,
        ],
        Q::CHECK_CONFIGURABLE_OUT_OF_STOCK => [
            'Configurables Out of Stock', 'Configurables with enabled children, none in stock', null, true,
        ],
        Q::CHECK_DUPLICATE_URL_KEY => ['Duplicate URL Keys', 'URL keys shared by several products', null, true],
    ];

    /**
     * @param Q $queries
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly Q $queries,
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
        return 'Counts of common product data issues: images, prices, descriptions, website and category '
            . 'assignment, orphaned or unbuyable composite products, configurables with every enabled child out of stock (default stock only), URL rewrites and duplicate URL keys.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports one count per check in a "general" facts section, plus one samples table
     * (up to 20 rows) for each check that has one.
     */
    public function getStatus(): array
    {
        $facts = [];
        $tables = [];

        foreach (self::CHECKS as $check => [$label, $samplesTitle, $metricKey, $hasSamples]) {
            $count = $this->queries->count($check);
            $facts[$check] = $metricKey !== null
                ? $this->field->trackableNumber($label, $count, $metricKey, MetricDefinition::AGGREGATION_LATEST)
                : $this->field->number($label, $count);

            if ($hasSamples) {
                $tables[$check . '_samples'] = $this->samplesTable($check, $samplesTitle);
            }
        }

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), $facts)] + $tables;
    }

    /**
     * Alertable metrics: missing-image, no-website and unbuyable-composite product counts.
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
                self::METRIC_NO_WEBSITE,
                'No Website',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                1500,
                null,
                description: '{value} enabled products are not assigned to any website, above the limit of {threshold}.',
                impact: 'These products cannot be seen or bought on the storefront.'
            ),
            new MetricDefinition(
                self::METRIC_COMPOSITE_UNBUYABLE,
                'Unbuyable Composite Products',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                1500,
                null,
                description: '{value} configurable, grouped or bundle products have nothing purchasable, '
                    . 'above the limit of {threshold}.',
                impact: 'Customers can see these products but cannot add anything to the cart.'
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

    /**
     * Builds the samples table for a check from its query rows.
     */
    private function samplesTable(string $check, string $title): \StackNuts\StackGauge\Api\Section\TableSection
    {
        $rows = [];

        if ($check === Q::CHECK_DUPLICATE_URL_KEY) {
            foreach ($this->queries->samples($check) as $row) {
                $rows[] = $this->field->array('', [
                    'url_key' => $this->field->varchar('URL Key', (string) $row['url_key']),
                    'products' => $this->field->number('Products', (int) $row['products']),
                    'skus' => $this->field->varchar('SKUs', (string) $row['skus']),
                ]);
            }
            return $this->section->table($check . '_samples', $title, '', $rows, 'url_key');
        }

        $extra = $this->queries->extraColumn($check);
        foreach ($this->queries->samples($check) as $row) {
            $cells = [
                'sku' => $this->field->varchar('SKU', (string) $row['sku']),
                'name' => $this->field->varchar('Name', (string) ($row['name'] ?? '')),
                'type_id' => $this->field->varchar('Type', (string) $row['type_id']),
            ];
            if ($extra !== null) {
                $cells[$extra[0]] = $this->field->varchar($extra[1], (string) ($row['extra'] ?? ''));
            }
            $rows[] = $this->field->array('', $cells);
        }

        return $this->section->table($check . '_samples', $title, '', $rows, 'sku');
    }
}
