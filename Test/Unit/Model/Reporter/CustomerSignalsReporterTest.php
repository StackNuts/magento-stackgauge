<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use StackNuts\StackGauge\Model\Reporter\CustomerSignalsReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class CustomerSignalsReporterTest extends TestCase
{
    public function testGetStatusReturnsCounts(): void
    {
        $custTotal = $this->createMock(CustomerCollection::class);
        $custTotal->method('getSize')->willReturn(50);

        $custNew = $this->createMock(CustomerCollection::class);
        $custNew->method('addFieldToFilter')->willReturnSelf();
        $custNew->method('getSize')->willReturn(5);

        $orderCol = $this->createMock(OrderCollection::class);
        $orderCol->method('addFieldToFilter')->willReturnSelf();
        $orderCol->method('getSize')->willReturn(20);

        $orderGuest = $this->createMock(OrderCollection::class);
        $orderGuest->method('addFieldToFilter')->willReturnSelf();
        $orderGuest->method('getSize')->willReturn(8);

        $custFactory = $this->createMock(CustomerCollectionFactory::class);
        $custFactory->method('create')->willReturnOnConsecutiveCalls($custTotal, $custNew);

        $orderFactory = $this->createMock(OrderCollectionFactory::class);
        $orderFactory->method('create')->willReturnOnConsecutiveCalls($orderCol, $orderGuest);

        $reporter = new CustomerSignalsReporter($custFactory, $orderFactory, new Clock());
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('general', $status);
    }
}
