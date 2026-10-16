<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Pricing\PricingQueries;
use StackNuts\StackGauge\Model\Reporter\PricingReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class PricingReporterTest extends TestCase
{
    /**
     * @param array<string,mixed> $deep
     * @param array<string,mixed> $zero
     * @param array<string,mixed> $special
     * @param list<array<string,mixed>> $sellers
     */
    private function reporter(
        array $deep = ['count' => 0, 'rows' => []],
        array $zero = ['count' => 0, 'rows' => []],
        array $special = ['count' => 0, 'rows' => []],
        array $sellers = []
    ): PricingReporter {
        $queries = $this->createMock(PricingQueries::class);
        $queries->method('deepDiscount')->willReturn($deep);
        $queries->method('zeroFinalPrice')->willReturn($zero);
        $queries->method('specialAboveRegular')->willReturn($special);
        $queries->method('topSellers')->willReturn($sellers);

        return new PricingReporter($queries, new Clock(), new Field(), new Section());
    }

    public function testIdentity(): void
    {
        $reporter = $this->reporter();

        $this->assertSame('pricing', $reporter->getName());
        $this->assertSame('Pricing', $reporter->getLabel());
        $this->assertSame('catalog', $reporter->getSubject());
        $this->assertSame(DeclaresCadenceInterface::CADENCE_DAILY, $reporter->getCadence());
        $this->assertSame(0, $reporter->getSlot());
    }

    public function testEmptyStoreReportsZerosAndEmptyTables(): void
    {
        $status = $this->reporter()->getStatus();

        $this->assertSame(
            ['general', 'deep_discounts', 'zero_final_prices', 'special_above_regular', 'top_sellers'],
            array_keys($status)
        );
        $fields = $status['general']->getFields();
        foreach (['deep_discount_count', 'zero_final_price_count', 'special_above_regular_count',
            'price_paid_below_catalog'] as $key) {
            $this->assertSame(0, $fields[$key]->getValue(), $key);
        }
        $this->assertSame([], $status['top_sellers']->getRows());
    }

    public function testCountsAndSamplesAreReported(): void
    {
        $indexRow = [
            'entity_id' => 1, 'sku' => 'A', 'name' => 'Alpha', 'website' => 'base', 'customer_group_id' => 0,
            'price' => '100.0000', 'final_price' => '20.0000', 'discount_percent' => '80.0000',
        ];
        $status = $this->reporter(
            ['count' => 3, 'rows' => [$indexRow]],
            ['count' => 2, 'rows' => [['sku' => 'Z', 'name' => 'Zed', 'website' => 'base',
                'price' => '10', 'final_price' => '0']]],
            ['count' => 4, 'rows' => [['sku' => 'S', 'name' => 'Sp', 'price' => '5', 'special_price' => '9']]]
        )->getStatus();

        $fields = $status['general']->getFields();
        $this->assertSame(3, $fields['deep_discount_count']->getValue());
        $this->assertSame(2, $fields['zero_final_price_count']->getValue());
        $this->assertSame(4, $fields['special_above_regular_count']->getValue());

        $row = $status['deep_discounts']->getRows()[0]->getValue();
        $this->assertSame(80.0, $row['discount_percent']->getValue());
        $this->assertSame(['sku', 'name', 'website', 'customer_group_id', 'price', 'final_price',
            'discount_percent'], array_keys($row));
        $this->assertSame(['sku', 'name', 'website', 'price', 'final_price'],
            array_keys($status['zero_final_prices']->getRows()[0]->getValue()));
        $this->assertSame(['sku', 'name', 'price', 'special_price'],
            array_keys($status['special_above_regular']->getRows()[0]->getValue()));
    }

    public function testTopSellersPercentAndUnderpricedCount(): void
    {
        $status = $this->reporter(sellers: [
            ['sku' => 'A', 'name' => 'A', 'qty' => 10.0, 'orders' => 4, 'avg_paid' => 10.0, 'catalog_price' => 100.0],
            ['sku' => 'B', 'name' => 'B', 'qty' => 5.0, 'orders' => 2, 'avg_paid' => 90.0, 'catalog_price' => 100.0],
            ['sku' => 'C', 'name' => 'C', 'qty' => 3.0, 'orders' => 1, 'avg_paid' => 5.0, 'catalog_price' => null],
            ['sku' => 'D', 'name' => 'D', 'qty' => 2.0, 'orders' => 1, 'avg_paid' => 5.0, 'catalog_price' => 0.0],
        ])->getStatus();

        $this->assertSame(1, $status['general']->getFields()['price_paid_below_catalog']->getValue());

        $rows = $status['top_sellers']->getRows();
        $this->assertCount(4, $rows);
        $this->assertSame('10%', $rows[0]->getValue()['paid_vs_catalog_percent']->getValue());
        $this->assertSame('90%', $rows[1]->getValue()['paid_vs_catalog_percent']->getValue());
        $this->assertSame('', $rows[2]->getValue()['paid_vs_catalog_percent']->getValue());
        $this->assertSame('', $rows[2]->getValue()['catalog_price']->getValue());
        $this->assertSame('', $rows[3]->getValue()['paid_vs_catalog_percent']->getValue());
    }

    public function testPaidVsCatalogPercent(): void
    {
        $this->assertSame(50.0, PricingReporter::paidVsCatalogPercent(5.0, 10.0));
        $this->assertSame(33.3, PricingReporter::paidVsCatalogPercent(1.0, 3.0));
        $this->assertNull(PricingReporter::paidVsCatalogPercent(5.0, null));
        $this->assertNull(PricingReporter::paidVsCatalogPercent(5.0, 0.0));
    }

    public function testMetricCatalog(): void
    {
        $metrics = [];
        foreach ($this->reporter()->getTrackableMetrics() as $metric) {
            $metrics[$metric->getMetricKey()] = $metric;
        }

        $this->assertEqualsCanonicalizing([
            'pricing.deep_discount_count',
            'pricing.zero_final_price_count',
            'pricing.top_sellers_underpriced_count',
        ], array_keys($metrics));

        foreach ($metrics as $metric) {
            $this->assertSame(MetricDefinition::OPERATOR_GT, $metric->getDefaultOperator());
            $this->assertEquals(0, $metric->getDefaultThreshold());
            $this->assertSame(1500, $metric->getDefaultWindowMinutes());
        }
        $this->assertSame(MetricDefinition::SEVERITY_CRITICAL, $metrics['pricing.zero_final_price_count']->getSeverity());
        $this->assertSame(MetricDefinition::SEVERITY_WARNING, $metrics['pricing.deep_discount_count']->getSeverity());
        $this->assertSame(
            MetricDefinition::SEVERITY_WARNING,
            $metrics['pricing.top_sellers_underpriced_count']->getSeverity()
        );
    }
}
