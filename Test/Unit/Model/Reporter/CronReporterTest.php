<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\CronReporter;

class CronReporterTest extends TestCase
{
    /**
     * @param list<ArrayField> $rows
     * @return array<string, ArrayField>
     */
    private function rowsByJobCode(array $rows): array
    {
        $byJobCode = [];
        foreach ($rows as $row) {
            $byJobCode[$row->getValue()['job_code']->getValue()] = $row;
        }

        return $byJobCode;
    }

    /**
     * @param array<string, string> $lastSuccess job_code => MAX(finished_at)
     * @param array<string, string> $nextScheduled job_code => MIN(scheduled_at)
     */
    private function reporter(array $lastSuccess, array $nextScheduled, ?string $mostRecentCreatedAt): CronReporter
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'group', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // getStatus() asks for last successes first, then next scheduled.
        $connection->method('fetchPairs')->willReturnOnConsecutiveCalls($lastSuccess, $nextScheduled);
        $connection->method('fetchOne')->willReturn($mostRecentCreatedAt ?? false);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new CronReporter($resource, new Field(), new Section());
    }

    public function testAliveIsTrueWhenMostRecentRowIsRecent(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $alive = $this->reporter([], [], $now)->getStatus()['general']->getFields()['alive'];

        $this->assertTrue($alive->getValue());
        $this->assertFalse($alive->jsonSerialize()['critical_when']);
    }

    public function testAliveIsFalseWhenMostRecentRowIsStale(): void
    {
        $stale = (new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $status = $this->reporter([], [], $stale)->getStatus();

        $this->assertFalse($status['general']->getFields()['alive']->getValue());
    }

    public function testAliveIsFalseWhenThereAreNoScheduleRows(): void
    {
        $general = $this->reporter([], [], null)->getStatus()['general']->getFields();

        $this->assertFalse($general['alive']->getValue());
        $this->assertSame('', $general['last_schedule_generated_at']->getValue());
    }

    public function testTimestampsAreDatetimeFields(): void
    {
        $status = $this->reporter(
            ['cron_dm' => '2026-05-07 12:00:05'],
            ['cron_dm' => '2026-05-07 13:00:00'],
            '2026-05-07 12:00:00'
        )->getStatus();

        $job = $status['jobs']->getRows()[0]->getValue();
        $this->assertSame('datetime', $job['last_success_at']->getType());
        $this->assertSame('datetime', $job['next_scheduled_at']->getType());
        $this->assertSame('datetime', $status['general']->getFields()['last_schedule_generated_at']->getType());
    }

    public function testJobsReportLastSuccessAndNextScheduledPerJobCode(): void
    {
        $reporter = $this->reporter(
            ['cron_dm' => '2026-05-07 12:00:05'],
            ['cron_dm' => '2026-05-07 13:00:00', 'ess_m2epro' => '2026-05-07 12:30:00'],
            '2026-05-07 12:00:00'
        );

        $jobs = $this->rowsByJobCode($reporter->getStatus()['jobs']->getRows());

        $this->assertSame(['cron_dm', 'ess_m2epro'], array_keys($jobs));

        $cronDm = $jobs['cron_dm']->getValue();
        $this->assertSame('2026-05-07 12:00:05', $cronDm['last_success_at']->getValue());
        $this->assertSame('2026-05-07 13:00:00', $cronDm['next_scheduled_at']->getValue());

        $essM2epro = $jobs['ess_m2epro']->getValue();
        $this->assertSame('', $essM2epro['last_success_at']->getValue());
        $this->assertSame('2026-05-07 12:30:00', $essM2epro['next_scheduled_at']->getValue());
    }

    public function testAJobWithOnlyASuccessRowIsStillListed(): void
    {
        $jobs = $this->rowsByJobCode(
            $this->reporter(['hourly_job' => '2026-05-07 12:00:05'], [], '2026-05-07 12:00:00')
                ->getStatus()['jobs']->getRows()
        );

        $this->assertSame('', $jobs['hourly_job']->getValue()['next_scheduled_at']->getValue());
    }
}
