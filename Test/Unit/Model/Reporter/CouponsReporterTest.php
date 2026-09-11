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
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\CouponsReporter;

class CouponsReporterTest extends TestCase
{
    private function mockSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    public function testGetStatusReturnsLifetimeTotalAcrossAllRules(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($this->mockSelect());
        $connection->method('fetchOne')->willReturn('247');
        $connection->method('fetchAll')->willReturn([]);

        $reporter = new CouponsReporter($resource);
        $status = $reporter->getStatus();

        $fields = $status['general']->getFields();
        $this->assertArrayHasKey('total_redemptions', $fields);
        $this->assertSame(247, $fields['total_redemptions']->getValue());
        $this->assertSame('coupons.total_redemptions', $fields['total_redemptions']->getMetricKey());
    }

    public function testActiveRulesTableShowsCodeOnlyForSpecificCouponType(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($this->mockSelect());
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAll')->willReturn([
            [
                'rule_id' => '4',
                'name' => '$4 Luma water bottle (save 70%)',
                'description' => 'Save on the water bottle',
                'coupon_type' => (string) Rule::COUPON_TYPE_SPECIFIC,
                'times_used' => '12',
                'from_date' => '2026-01-01',
                'to_date' => null,
                'coupon_count' => '1',
                'code' => 'BOTTLE4',
            ],
            [
                'rule_id' => '1',
                'name' => 'Buy 3 tee shirts and get the 4th free',
                'description' => null,
                'coupon_type' => (string) Rule::COUPON_TYPE_NO_COUPON,
                'times_used' => '0',
                'from_date' => '2026-01-01',
                'to_date' => '2026-12-31',
                'coupon_count' => '0',
                'code' => null,
            ],
            [
                'rule_id' => '7',
                'name' => 'VIP referral codes',
                'description' => null,
                'coupon_type' => (string) Rule::COUPON_TYPE_SPECIFIC,
                'times_used' => '31',
                'from_date' => '2026-01-01',
                'to_date' => null,
                'coupon_count' => '3',
                'code' => 'REF001',
            ],
        ]);

        $reporter = new CouponsReporter($resource);
        $status = $reporter->getStatus();

        $rows = $status['active_rules']->getRows();
        $this->assertCount(3, $rows);

        $specific = $rows[0]->getValue();
        $this->assertSame('Specific Coupon', $specific['coupon_type']->getValue());
        $this->assertSame('BOTTLE4', $specific['code']->getValue());
        $this->assertSame('2026-01-01 00:00:00', $specific['from_date']->getValue());
        $this->assertSame('', $specific['to_date']->getValue());
        $this->assertSame(12, $specific['times_used']->getValue());

        $noCoupon = $rows[1]->getValue();
        $this->assertSame('No Coupon', $noCoupon['coupon_type']->getValue());
        $this->assertSame('', $noCoupon['code']->getValue());
        $this->assertSame('2026-12-31 00:00:00', $noCoupon['to_date']->getValue());

        // A SPECIFIC-type rule can still carry a batch of many generated codes (admin's "Use
        // Auto Generation" checkbox) - coupon_type alone doesn't imply exactly one code, so a
        // multi-code SPECIFIC rule must blank the single "code" field the same as a NO_COUPON
        // rule, showing its count instead.
        $batch = $rows[2]->getValue();
        $this->assertSame('Specific Coupon', $batch['coupon_type']->getValue());
        $this->assertSame('', $batch['code']->getValue());
        $this->assertSame(3, $batch['coupon_count']->getValue());
    }

    public function testDeclaresTheTotalRedemptionsTrackableMetric(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $reporter = new CouponsReporter($resource);
        $metrics = $reporter->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('coupons.total_redemptions', $metrics[0]->getMetricKey());
        $this->assertSame('delta', $metrics[0]->getAggregation());
    }

    public function testUsesDailyCadence(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $reporter = new CouponsReporter($resource);

        $this->assertSame('daily', $reporter->getCadence());
    }

    public function testDeclaresTheCommerceSection(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $reporter = new CouponsReporter($resource);

        $this->assertSame('commerce', $reporter->getSection());
    }
}
