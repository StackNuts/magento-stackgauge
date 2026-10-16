<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\AbandonedCartsReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class AbandonedCartsReporterTest extends TestCase
{
    /**
     * @param array<string, mixed> $totals
     * @param list<array<string, string>> $samples
     */
    private function reporter(array $totals, array $samples): AbandonedCartsReporter
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quote')->willReturnCallback(fn ($v) => "'" . $v . "'");
        $connection->method('fetchRow')->willReturn($totals);
        $connection->method('fetchAll')->willReturn($samples);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new AbandonedCartsReporter($resource, new Clock(), new Field(), new Section());
    }

    public function testGetStatusReturnsCountsAndSamples(): void
    {
        $status = $this->reporter(
            ['total' => '40', 'recent' => '3'],
            [['entity_id' => '101', 'updated_at' => '2026-10-15 10:00:00']]
        )->getStatus();

        $general = $status['general']->getFields();
        $this->assertSame(3, $general['count']->getValue());
        $this->assertSame(40, $general['total_idle']->getValue());
        $rows = $status['samples']->getRows();
        $this->assertCount(1, $rows);
        $this->assertSame('101', $rows[0]->getValue()['quote_id']->getValue());
        $this->assertSame('datetime', $rows[0]->getValue()['updated_at']->getType());
    }

    public function testNoCartsReportsZero(): void
    {
        $status = $this->reporter(['total' => '0', 'recent' => null], [])->getStatus();

        $this->assertSame(0, $status['general']->getFields()['count']->getValue());
        $this->assertSame([], $status['samples']->getRows());
    }

    public function testCountIsALatestValueAgainstAThresholdNotADelta(): void
    {
        $reporter = $this->reporter(['total' => '0', 'recent' => '0'], []);

        $this->assertSame('latest', $reporter->getStatus()['general']->getFields()['count']->getAggregation());

        $metrics = $reporter->getTrackableMetrics();
        $this->assertCount(1, $metrics);
        $this->assertSame('abandoned_carts.count', $metrics[0]->getMetricKey());
        $this->assertSame('latest', $metrics[0]->getAggregation());
    }
}
