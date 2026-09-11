<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Quote\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use StackNuts\StackGauge\Model\Reporter\AbandonedCartsReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class AbandonedCartsReporterTest extends TestCase
{
    public function testGetStatusReturnsCountsAndSamples(): void
    {
        $colCount = $this->createMock(QuoteCollection::class);
        $colCount->method('addFieldToFilter')->willReturnSelf();
        $colCount->method('getSize')->willReturn(3);

        $sampleItem = new class { public function getId() { return 101; } };
        $colSample = $this->createMock(QuoteCollection::class);
        $colSample->method('addFieldToFilter')->willReturnSelf();
        $colSample->method('setPageSize')->willReturnSelf();
        $colSample->method('getItems')->willReturn([$sampleItem]);

        $factory = $this->createMock(QuoteCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($colCount, $colSample);

        $reporter = new AbandonedCartsReporter($factory, new Clock());
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('general', $status);
        $this->assertArrayHasKey('samples', $status);
        $this->assertSame(3, $status['general']->getFields()['count']->getValue());
        $this->assertCount(1, $status['samples']->getRows());
    }

    public function testCountIsTrackedAsGrowthNotAnAbsoluteSnapshot(): void
    {
        // Magento's own quote cleanup only removes carts 30+ days old, while this reporter
        // calls a cart "abandoned" after 24h - so the raw count always carries a large,
        // normal backlog. Alerting must be on growth (delta), not the absolute value, or the
        // default threshold would be permanently breached on any real store.
        $factory = $this->createMock(\Magento\Quote\Model\ResourceModel\Quote\CollectionFactory::class);
        $col = $this->createMock(QuoteCollection::class);
        $col->method('addFieldToFilter')->willReturnSelf();
        $col->method('setPageSize')->willReturnSelf();
        $col->method('getSize')->willReturn(0);
        $col->method('getItems')->willReturn([]);
        $factory->method('create')->willReturn($col);

        $reporter = new AbandonedCartsReporter($factory, new Clock());

        $this->assertSame(
            'delta',
            $reporter->getStatus()['general']->getFields()['count']->getAggregation()
        );

        $metrics = $reporter->getTrackableMetrics();
        $this->assertCount(1, $metrics);
        $this->assertSame('delta', $metrics[0]->getAggregation());
    }
}
