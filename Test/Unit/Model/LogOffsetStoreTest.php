<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\LogOffsetStore;

class LogOffsetStoreTest extends TestCase
{
    /**
     * @param array<string, array{byte_offset: string, running_count: string, fingerprint?: string|null}> $rows
     */
    private function store(array $rows, ?AdapterInterface &$connection = null): LogOffsetStore
    {
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $key = null;
        $select->method('where')->willReturnCallback(function ($condition, $value) use ($select, &$key) {
            $key = $value;
            return $select;
        });
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(function () use ($rows, &$key) {
            return $rows[$key] ?? false;
        });

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return new LogOffsetStore($resourceConnection);
    }

    public function testGetReturnsNullWhenNoRowStoredYet(): void
    {
        $this->assertNull($this->store([])->get('exception.log'));
    }

    public function testGetReadsARowWrittenByAnOlderVersionWithNoFingerprint(): void
    {
        $store = $this->store(['exception.log' => ['byte_offset' => '1024', 'running_count' => '7']]);

        $this->assertSame(
            ['byte_offset' => 1024, 'running_count' => 7, 'fingerprint_crc' => 0, 'fingerprint_length' => 0],
            $store->get('exception.log')
        );
    }

    public function testGetParsesTheFingerprintColumn(): void
    {
        $store = $this->store([
            'exception.log' => ['byte_offset' => '1024', 'running_count' => '7', 'fingerprint' => '63:200'],
        ]);

        $this->assertSame(
            ['byte_offset' => 1024, 'running_count' => 7, 'fingerprint_crc' => 99, 'fingerprint_length' => 512],
            $store->get('exception.log')
        );
    }

    public function testStagingWritesNothingUntilCommit(): void
    {
        $store = $this->store([], $connection);
        $connection->expects($this->never())->method('insertOnDuplicate');
        $store->stage('exception.log', 2048, 9, 5, 100);
        $store->discard();
        $store->commit();
    }

    public function testCommitUpsertsTheCursorWithItsFingerprintOnce(): void
    {
        $store = $this->store([], $connection);
        $connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'stacknuts_stackgauge_log_offset',
                [
                    'file_path' => 'exception.log',
                    'byte_offset' => 2048,
                    'running_count' => 9,
                    'fingerprint' => '5:64',
                ],
                ['byte_offset', 'running_count', 'fingerprint']
            );

        $store->stage('exception.log', 2048, 9, 5, 100);
        $store->commit();
        $store->commit();
    }
}
