<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\CatalogReporter;
use StackNuts\StackGauge\Model\Util\EnabledProductSql;

class CatalogReporterTest extends TestCase
{
    private function reporter(string $count, ?string &$sql = null): CatalogReporter
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $query) use ($count, &$sql) {
            $sql = $query;

            return $count;
        });

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $enabled = $this->createMock(EnabledProductSql::class);
        $enabled->method('from')->willReturn('FROM catalog_product_entity AS e JOIN status_join AS st ON 1 ');

        return new CatalogReporter($resource, $enabled, new Field(), new Section());
    }

    public function testReportsTheEnabledProductCountFromRawSqlNotACollection(): void
    {
        $sql = null;
        $status = $this->reporter('1890', $sql)->getStatus();

        $field = $status['general']->getFields()['products_enabled_count'];
        $this->assertSame(1890, $field->getValue());
        $this->assertSame('catalog.products_enabled_count', $field->getMetricKey());
        $this->assertSame('SELECT COUNT(*) FROM catalog_product_entity AS e JOIN status_join AS st ON 1 ', $sql);
    }

    public function testEmptyCatalogueReportsZero(): void
    {
        $status = $this->reporter('0')->getStatus();

        $this->assertSame(0, $status['general']->getFields()['products_enabled_count']->getValue());
    }

    public function testDeclaresACriticalAlertWhenNoProductsAreEnabled(): void
    {
        $metrics = $this->reporter('1')->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('catalog.products_enabled_count', $metrics[0]->getMetricKey());
        $this->assertSame(MetricDefinition::AGGREGATION_LATEST, $metrics[0]->getAggregation());
        $this->assertSame(MetricDefinition::SEVERITY_CRITICAL, $metrics[0]->getSeverity());
    }

    public function testRunsInTheCatalogSubjectAndDailyCadence(): void
    {
        $reporter = $this->reporter('1');

        $this->assertSame('catalog', $reporter->getName());
        $this->assertSame('catalog', $reporter->getSubject());
        $this->assertSame(1, $reporter->getSlot());
    }
}
