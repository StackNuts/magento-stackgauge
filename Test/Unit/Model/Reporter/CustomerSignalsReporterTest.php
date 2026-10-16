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
use StackNuts\StackGauge\Model\Reporter\CustomerSignalsReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class CustomerSignalsReporterTest extends TestCase
{
    public function testGetStatusReturnsCounts(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quote')->willReturnCallback(static fn ($v): string => "'" . $v . "'");
        // Customers first, then orders.
        $connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['total' => '50', 'recent' => '5'],
            ['total' => '20', 'guest' => '8']
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $status = (new CustomerSignalsReporter($resource, new Clock(), new Field(), new Section()))->getStatus();

        $fields = $status['general']->getFields();
        $this->assertSame(50, $fields['total_customers']->getValue());
        $this->assertSame(5, $fields['new_customers_30d']->getValue());
        $this->assertSame(8, $fields['guest_orders_30d']->getValue());
        $this->assertSame(12, $fields['logged_in_orders_30d']->getValue());
    }

    public function testNoRowsReportZeroes(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quote')->willReturnArgument(0);
        $connection->method('fetchRow')->willReturn(false);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $fields = (new CustomerSignalsReporter($resource, new Clock(), new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame(0, $fields['total_customers']->getValue());
        $this->assertSame(0, $fields['logged_in_orders_30d']->getValue());
    }
}
