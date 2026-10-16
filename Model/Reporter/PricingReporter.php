<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Pricing\PricingQueries;
use StackNuts\StackGauge\Model\Util\Clock;

/**
 * Catalogue pricing sanity checks, read from the price index and the default-scope price
 * attributes: products discounted implausibly deeply, products priced at zero (a free checkout),
 * special prices set above the regular price, and the best-selling SKUs of the last week with
 * what customers actually paid compared with the current catalogue price. The deep-discount,
 * zero-price and under-priced-top-seller counts are alertable metrics; the special-above-regular
 * count is display-only, since Magento uses the lower price so it is data hygiene rather than
 * anything a customer sees.
 */
class PricingReporter implements
    ReporterInterface,
    MetricCatalogInterface,
    DeclaresCadenceInterface,
    DeclaresSectionInterface,
    DeclaresSubjectInterface,
    DeclaresSlotInterface
{
    use CommerceSectionTrait;
    use DailyCadenceTrait;

    private const SCHEMA_VERSION = '1.0';

    private const METRIC_DEEP_DISCOUNT = 'pricing.deep_discount_count';
    private const METRIC_ZERO_FINAL_PRICE = 'pricing.zero_final_price_count';
    private const METRIC_TOP_SELLERS_UNDERPRICED = 'pricing.top_sellers_underpriced_count';

    /**
     * A final price more than this fraction below the regular price counts as a deep discount
     * (0.6 = more than 60% off), and a top seller's average paid price below catalog price
     * times (1 - this) counts as under-priced.
     */
    public const DEEP_DISCOUNT_RATIO = 0.6;

    /**
     * Look-back window for the top-sellers table, in days.
     */
    public const TOP_SELLERS_WINDOW_DAYS = 7;

    /**
     * @param PricingQueries $queries
     * @param Clock $clock
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly PricingQueries $queries,
        private readonly Clock $clock,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the pricing reporter.
     */
    public function getName(): string
    {
        return 'pricing';
    }

    /**
     * Human-readable label for the pricing reporter block.
     */
    public function getLabel(): string
    {
        return 'Pricing';
    }

    /**
     * One-line summary of the pricing reporter, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Pricing sanity checks: deep discounts, zero prices, special prices above the regular '
            . 'price, and what top sellers were actually sold for.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports pricing anomaly counts with sample tables, plus the top sellers of the last week
     * with their average paid price against the current catalogue price.
     */
    public function getStatus(): array
    {
        $now = $this->clock->now();
        $deep = $this->queries->deepDiscount(self::DEEP_DISCOUNT_RATIO);
        $zero = $this->queries->zeroFinalPrice();
        $special = $this->queries->specialAboveRegular($now);
        $sellers = $this->topSellerRows(
            $this->queries->topSellers($now->modify(sprintf('-%d days', self::TOP_SELLERS_WINDOW_DAYS)))
        );

        return [
            'general' => $this->section->facts('general', 'General', 'Pricing anomaly counts.', [
                'deep_discount_count' => $this->field->trackableNumber(
                    'Deep Discounts',
                    $deep['count'],
                    self::METRIC_DEEP_DISCOUNT,
                    MetricDefinition::AGGREGATION_LATEST
                ),
                'zero_final_price_count' => $this->field->trackableNumber(
                    'Zero Final Price',
                    $zero['count'],
                    self::METRIC_ZERO_FINAL_PRICE,
                    MetricDefinition::AGGREGATION_LATEST
                ),
                'special_above_regular_count' => $this->field->number(
                    'Special Price Above Regular',
                    $special['count']
                ),
                'price_paid_below_catalog' => $this->field->trackableNumber(
                    'Top Sellers Sold Below Catalog Price',
                    $sellers['underpriced'],
                    self::METRIC_TOP_SELLERS_UNDERPRICED,
                    MetricDefinition::AGGREGATION_LATEST
                ),
            ]),
            'deep_discounts' => $this->section->table(
                'deep_discounts',
                'Deep Discounts',
                'Enabled products discounted by more than ' . (int) round(self::DEEP_DISCOUNT_RATIO * 100)
                . '% for some website and customer group (worst discount first).',
                array_map(fn (array $r): ArrayField => $this->indexRow($r, true), $deep['rows']),
                keyName: 'sku'
            ),
            'zero_final_prices' => $this->section->table(
                'zero_final_prices',
                'Zero Final Price',
                'Enabled products with a final or minimum price of zero or below.',
                array_map(fn (array $r): ArrayField => $this->indexRow($r, false), $zero['rows']),
                keyName: 'sku'
            ),
            'special_above_regular' => $this->section->table(
                'special_above_regular',
                'Special Price Above Regular',
                'Enabled products whose active special price is higher than the regular price. Magento '
                . 'uses the lower price, so customers are unaffected.',
                array_map(fn (array $r): ArrayField => $this->field->array((string) $r['sku'], [
                    'sku' => $this->field->varchar('SKU', (string) $r['sku']),
                    'name' => $this->field->varchar('Name', (string) $r['name']),
                    'price' => $this->field->number('Price', round((float) $r['price'], 2)),
                    'special_price' => $this->field->number('Special Price', round((float) $r['special_price'], 2)),
                ]), $special['rows']),
                keyName: 'sku'
            ),
            'top_sellers' => $this->section->table(
                'top_sellers',
                'Top Sellers',
                'Top SKUs by quantity ordered in the last ' . self::TOP_SELLERS_WINDOW_DAYS
                . ' days (excluding canceled orders) with the average price paid after discounts against '
                . 'the current catalogue price.',
                $sellers['rows'],
                keyName: 'sku'
            ),
        ];
    }

    /**
     * Alertable metrics for the pricing reporter: deep discounts, zero prices and top sellers
     * sold well below their catalogue price.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_DEEP_DISCOUNT,
                'Deep Discounted Products',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                1500,
                null,
                description: '{value} products are discounted by more than 60% (alert above {threshold}).',
                impact: 'A price or special-price mistake may be selling products at a heavy loss.'
            ),
            new MetricDefinition(
                self::METRIC_ZERO_FINAL_PRICE,
                'Zero Final Price Products',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                1500,
                null,
                description: '{value} enabled products have a final price of zero (alert above {threshold}).',
                impact: 'Customers can check out for free.',
                severity: MetricDefinition::SEVERITY_CRITICAL
            ),
            new MetricDefinition(
                self::METRIC_TOP_SELLERS_UNDERPRICED,
                'Top Sellers Sold Below Catalog Price',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                1500,
                null,
                description: '{value} top-selling products were sold for under 40% of their current catalogue '
                    . 'price on average in the last 7 days (alert above {threshold}).',
                impact: 'Products may have been mispriced and sold at a heavy loss.'
            ),
        ];
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'catalog';
    }

    /**
     * Run 0 within the daily tier. The daily tier has two slots (0 and 1), so this keeps the
     * pricing queries away from slot 1.
     */
    public function getSlot(): int
    {
        return 0;
    }

    /**
     * Percent of the catalogue price that was actually paid; null when there is no usable
     * catalogue price to compare with (deleted product, or a price of zero).
     *
     * @param float $averagePaid
     * @param float|null $catalogPrice
     */
    public static function paidVsCatalogPercent(float $averagePaid, ?float $catalogPrice): ?float
    {
        if ($catalogPrice === null || $catalogPrice <= 0.0) {
            return null;
        }

        return round($averagePaid / $catalogPrice * 100, 1);
    }

    /**
     * One index-price sample row; deep-discount samples additionally carry the discount percent
     * and customer group.
     *
     * @param array<string,mixed> $row
     * @param bool $withDiscount
     */
    private function indexRow(array $row, bool $withDiscount): ArrayField
    {
        $fields = [
            'sku' => $this->field->varchar('SKU', (string) $row['sku']),
            'name' => $this->field->varchar('Name', (string) $row['name']),
            'website' => $this->field->varchar('Website', (string) $row['website']),
        ];
        if ($withDiscount) {
            $fields['customer_group_id'] = $this->field->number('Customer Group ID', (int) $row['customer_group_id']);
        }
        $fields['price'] = $this->field->number('Price', round((float) $row['price'], 2));
        $fields['final_price'] = $this->field->number('Final Price', round((float) $row['final_price'], 2));
        if ($withDiscount) {
            $fields['discount_percent'] = $this->field->number(
                'Discount',
                round((float) $row['discount_percent'], 1),
                unit: Unit::PERCENT
            );
        }

        return $this->field->array((string) $row['sku'], $fields);
    }

    /**
     * Builds the top-seller table rows and counts those sold well below catalogue price.
     *
     * @param list<array{sku: string, name: string, qty: float, orders: int, avg_paid: float, catalog_price: float|null}> $sellers
     * @return array{rows: list<ArrayField>, underpriced: int}
     */
    private function topSellerRows(array $sellers): array
    {
        $rows = [];
        $underpriced = 0;

        foreach ($sellers as $seller) {
            $catalog = $seller['catalog_price'];
            $percent = self::paidVsCatalogPercent($seller['avg_paid'], $catalog);
            if ($percent !== null && $seller['avg_paid'] < $catalog * (1 - self::DEEP_DISCOUNT_RATIO)) {
                $underpriced++;
            }

            // Table rows must all share the same columns and a number cell can't be null, so an
            // unknown catalogue price (deleted product) or an unusable comparison (price of zero)
            // is reported as an empty string rather than a misleading 0.
            $fields = [
                'sku' => $this->field->varchar('SKU', $seller['sku']),
                'name' => $this->field->varchar('Name', $seller['name']),
                'qty_ordered' => $this->field->number('Qty Ordered', round($seller['qty'], 2)),
                'order_count' => $this->field->number('Orders', $seller['orders']),
                'avg_paid_price' => $this->field->number('Average Paid Price', round($seller['avg_paid'], 2)),
                'catalog_price' => $this->field->varchar(
                    'Catalog Price',
                    $catalog === null ? '' : number_format($catalog, 2, '.', '')
                ),
                'paid_vs_catalog_percent' => $this->field->varchar(
                    'Paid vs Catalog (%)',
                    $percent === null ? '' : rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . '%'
                ),
            ];

            $rows[] = $this->field->array($seller['sku'], $fields);
        }

        return ['rows' => $rows, 'underpriced' => $underpriced];
    }
}
