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
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\CouponsReporter;
use StackNuts\StackGauge\Model\Util\Clock;

class CouponsReporterTest extends TestCase
{
    /**
     * @param string $linkField
     */
    private function metadataPool(string $linkField = 'rule_id'): MetadataPool
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        return $pool;
    }

    /**
     * @param ResourceConnection $resource
     * @param string $linkField
     */
    private function reporter(ResourceConnection $resource, string $linkField = 'rule_id'): CouponsReporter
    {
        return new CouponsReporter($resource, new Field(), new Section(), $this->metadataPool($linkField), new Clock());
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param array<int|string,int|string> $counts
     * @param list<string> $wheres collects every where() clause passed to any select
     */
    private function resource(array $rules, array $counts = [], array &$wheres = []): ResourceConnection
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $connection->method('select')->willReturnCallback(function () use (&$wheres): Select {
            $select = $this->createMock(Select::class);
            foreach (['from', 'where', 'order', 'group', 'limit'] as $method) {
                $select->method($method)->willReturnCallback(function (...$args) use ($select, $method, &$wheres) {
                    if ($method === 'where') {
                        $wheres[] = (string) $args[0];
                    }

                    return $select;
                });
            }

            return $select;
        });
        $connection->method('fetchOne')->willReturn('247');
        $connection->method('fetchAll')->willReturn($rules);
        $connection->method('fetchPairs')->willReturn($counts);

        return $resource;
    }

    public function testGetStatusReturnsLifetimeTotalAcrossAllRules(): void
    {
        $status = $this->reporter($this->resource([]))->getStatus();

        $fields = $status['general']->getFields();
        $this->assertSame(247, $fields['total_redemptions']->getValue());
        $this->assertSame('coupons.total_redemptions', $fields['total_redemptions']->getMetricKey());
    }

    public function testActiveRulesTableNeverContainsCodesOrDescriptions(): void
    {
        $status = $this->reporter($this->resource(
            [
                [
                    'link_id' => '4', 'rule_id' => '4', 'name' => 'Bottle', 'description' => 'SECRET-DESC',
                    'code' => 'BOTTLE4', 'coupon_type' => (string) Rule::COUPON_TYPE_SPECIFIC,
                    'times_used' => '12', 'from_date' => '2026-01-01', 'to_date' => null,
                ],
                [
                    'link_id' => '1', 'rule_id' => '1', 'name' => 'Tees', 'coupon_type' => (string) Rule::COUPON_TYPE_NO_COUPON,
                    'times_used' => '0', 'from_date' => '2026-01-01', 'to_date' => '2026-12-31',
                ],
            ],
            ['4' => '3']
        ))->getStatus();

        $rows = $status['active_rules']->getRows();
        $this->assertCount(2, $rows);

        $first = $rows[0]->getValue();
        $this->assertArrayNotHasKey('code', $first);
        $this->assertArrayNotHasKey('description', $first);
        $this->assertStringNotContainsString('BOTTLE4', json_encode($status['active_rules']));
        $this->assertStringNotContainsString('SECRET-DESC', json_encode($status['active_rules']));
        $this->assertSame('Specific Coupon', $first['coupon_type']->getValue());
        $this->assertSame(3, $first['coupon_count']->getValue());
        $this->assertSame('2026-01-01', $first['from_date']->getValue());
        $this->assertSame('', $first['to_date']->getValue());

        $second = $rows[1]->getValue();
        $this->assertSame('No Coupon', $second['coupon_type']->getValue());
        $this->assertSame(0, $second['coupon_count']->getValue());
        $this->assertSame('2026-12-31', $second['to_date']->getValue());
    }

    public function testExpiredRulesAreFilteredAndOpenSourceHasNoStagingFilter(): void
    {
        $wheres = [];
        $this->reporter($this->resource([], [], $wheres))->getStatus();

        $this->assertNotEmpty(array_filter($wheres, static fn (string $w): bool => str_contains($w, 'to_date')));
        $this->assertEmpty(array_filter($wheres, static fn (string $w): bool => str_contains($w, 'created_in')));
    }

    public function testStagingRestrictsToCurrentRowsAndCountsByRowId(): void
    {
        $wheres = [];
        $status = $this->reporter(
            $this->resource(
                [[
                    'link_id' => '90', 'rule_id' => '4', 'name' => 'Bottle', 'coupon_type' => (string) Rule::COUPON_TYPE_SPECIFIC,
                    'times_used' => '12', 'from_date' => null, 'to_date' => null,
                ]],
                ['90' => '5'],
                $wheres
            ),
            'row_id'
        )->getStatus();

        // Both the total and the rule listing are limited to the current staging row.
        $this->assertCount(2, array_filter($wheres, static fn (string $w): bool => str_contains($w, 'updated_in > UNIX_TIMESTAMP()')));
        $this->assertSame(5, $status['active_rules']->getRows()[0]->getValue()['coupon_count']->getValue());
    }

    public function testMoreThanTheRowLimitIsTruncatedAndFlagged(): void
    {
        $rules = [];
        for ($i = 1; $i <= 501; $i++) {
            $rules[] = [
                'link_id' => (string) $i, 'rule_id' => (string) $i, 'name' => 'Rule ' . $i,
                'coupon_type' => (string) Rule::COUPON_TYPE_NO_COUPON, 'times_used' => '0',
                'from_date' => null, 'to_date' => null,
            ];
        }

        $table = $this->reporter($this->resource($rules))->getStatus()['active_rules'];

        $this->assertCount(500, $table->getRows());
        $this->assertTrue($table->jsonSerialize()['truncated']);
    }

    public function testDeclaresTheTotalRedemptionsTrackableMetric(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $reporter = $this->reporter($resource);
        $metrics = $reporter->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('coupons.total_redemptions', $metrics[0]->getMetricKey());
        $this->assertSame('delta', $metrics[0]->getAggregation());
    }

    public function testUsesDailyCadence(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $reporter = $this->reporter($resource);

        $this->assertSame('daily', $reporter->getCadence());
    }

    public function testDeclaresTheCommerceSection(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $reporter = $this->reporter($resource);

        $this->assertSame('commerce', $reporter->getSection());
    }
}
