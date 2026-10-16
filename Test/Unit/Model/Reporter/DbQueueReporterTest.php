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
use StackNuts\StackGauge\Model\Reporter\DbQueueReporter;

class DbQueueReporterTest extends TestCase
{
    private function mockSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    /**
     * @param list<array{name: string, backlog: string, errors: string}> $rows
     */
    private function reporter(array $rows, bool $tablesExist = true): DbQueueReporter
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('isTableExists')->willReturn($tablesExist);
        $connection->method('select')->willReturn($this->mockSelect());

        // Reporter issues two queries: grouped status counts first, then the queue names.
        $counts = [];
        $queues = [];
        foreach ($rows as $i => $row) {
            $queues[] = ['id' => (string)($i + 1), 'name' => $row['name']];
            if ((int)$row['backlog'] > 0) {
                $counts[] = ['queue_id' => (string)($i + 1), 'status' => '2', 'cnt' => $row['backlog']];
            }
            if ((int)$row['errors'] > 0) {
                $counts[] = ['queue_id' => (string)($i + 1), 'status' => '6', 'cnt' => $row['errors']];
            }
        }
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls($counts, $queues);

        return new DbQueueReporter($resource, new Field(), new Section());
    }

    public function testEmptyWhenNoQueuesRegistered(): void
    {
        $status = $this->reporter([])->getStatus();

        $fields = $status['general']->getFields();
        $this->assertSame(0, $fields['queue_count']->getValue());
        $this->assertSame(0, $fields['total_backlog']->getValue());
        $this->assertSame(0, $fields['total_errors']->getValue());
        $this->assertSame([], $status['queues']->getRows());
    }

    public function testMissingTablesAreTreatedAsNoQueuesNotAFailure(): void
    {
        $status = $this->reporter([], tablesExist: false)->getStatus();

        $this->assertSame(0, $status['general']->getFields()['queue_count']->getValue());
    }

    public function testReportsPerQueueBacklogAndErrors(): void
    {
        $status = $this->reporter([
            ['name' => 'inventory.reservations.update', 'backlog' => '3', 'errors' => '0'],
            ['name' => 'media.gallery.synchronization', 'backlog' => '0', 'errors' => '1'],
            ['name' => 'saveConfig', 'backlog' => '0', 'errors' => '0'],
        ])->getStatus();

        $general = $status['general']->getFields();
        $this->assertSame(3, $general['queue_count']->getValue());
        $this->assertSame(3, $general['total_backlog']->getValue());
        $this->assertSame(1, $general['total_errors']->getValue());

        $rows = $status['queues']->getRows();
        $this->assertCount(3, $rows);

        $reservations = $rows[0]->getValue();
        $this->assertSame('inventory.reservations.update', $reservations['name']->getValue());
        $this->assertSame(3, $reservations['backlog']->getValue());
        $this->assertSame(0, $reservations['errors']->getValue());

        $gallery = $rows[1]->getValue();
        $this->assertSame(0, $gallery['backlog']->getValue());
        $this->assertSame(1, $gallery['errors']->getValue());

        $quiet = $rows[2]->getValue();
        $this->assertSame('saveConfig', $quiet['name']->getValue());
        $this->assertSame(0, $quiet['backlog']->getValue());
        $this->assertSame(0, $quiet['errors']->getValue());
    }

    public function testAQueryFailurePropagatesInsteadOfReadingAsZeroBacklog(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturn($this->mockSelect());
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(\RuntimeException::class);
        (new DbQueueReporter($resource, new Field(), new Section()))->getStatus();
    }

    public function testDeclaresTheDataSection(): void
    {
        $reporter = $this->reporter([]);

        $this->assertSame('data', $reporter->getSection());
    }

    public function testDeclaresBacklogAndErrorTrackableMetrics(): void
    {
        $metrics = $this->reporter([])->getTrackableMetrics();

        $this->assertCount(2, $metrics);
        $this->assertSame('db_queue.total_backlog', $metrics[0]->getMetricKey());
        $this->assertSame('db_queue.total_errors', $metrics[1]->getMetricKey());
    }
}
