<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Cron\Model\ResourceModel\Schedule\Collection as ScheduleCollection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use StackNuts\StackGauge\Api\Field\ArrayField;
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

    private function scheduleRow(string $jobCode, string $status, ?string $createdAt, ?string $finishedAt, ?string $scheduledAt): object
    {
        return new class ($jobCode, $status, $createdAt, $finishedAt, $scheduledAt) {
            public function __construct(
                private string $jobCode,
                private string $status,
                private ?string $createdAt,
                private ?string $finishedAt,
                private ?string $scheduledAt
            ) {
            }

            public function getJobCode(): string
            {
                return $this->jobCode;
            }

            public function getStatus(): string
            {
                return $this->status;
            }

            public function getCreatedAt(): ?string
            {
                return $this->createdAt;
            }

            public function getFinishedAt(): ?string
            {
                return $this->finishedAt;
            }

            public function getScheduledAt(): ?string
            {
                return $this->scheduledAt;
            }
        };
    }

    private function reporterWithRows(array $rows): CronReporter
    {
        $collection = $this->createMock(ScheduleCollection::class);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        $factory = $this->createMock(ScheduleCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new CronReporter($factory);
    }

    public function testAliveIsTrueWhenMostRecentRowIsRecent(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $reporter = $this->reporterWithRows([
            $this->scheduleRow('cron_dm', 'success', $now, $now, null),
        ]);

        $status = $reporter->getStatus();
        $alive = $status['general']->getFields()['alive'];

        $this->assertTrue($alive->getValue());
        $this->assertFalse($alive->jsonSerialize()['critical_when']);
    }

    public function testAliveIsFalseWhenMostRecentRowIsStale(): void
    {
        $stale = (new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $reporter = $this->reporterWithRows([
            $this->scheduleRow('cron_dm', 'success', $stale, $stale, null),
        ]);

        $status = $reporter->getStatus();

        $this->assertFalse($status['general']->getFields()['alive']->getValue());
    }

    public function testJobsReportLastSuccessAndNextScheduledPerJobCode(): void
    {
        $reporter = $this->reporterWithRows([
            $this->scheduleRow('cron_dm', 'success', '2026-05-07 12:00:00', '2026-05-07 12:00:05', null),
            $this->scheduleRow('cron_dm', 'pending', '2026-05-07 12:00:00', null, '2026-05-07 13:00:00'),
            $this->scheduleRow('ess_m2epro', 'pending', '2026-05-07 12:00:00', null, '2026-05-07 12:30:00'),
        ]);

        $jobs = $this->rowsByJobCode($reporter->getStatus()['jobs']->getRows());

        $this->assertSame(['cron_dm', 'ess_m2epro'], array_keys($jobs));

        $cronDm = $jobs['cron_dm']->getValue();
        $this->assertSame('2026-05-07 12:00:05', $cronDm['last_success_at']->getValue());
        $this->assertSame('2026-05-07 13:00:00', $cronDm['next_scheduled_at']->getValue());

        $essM2epro = $jobs['ess_m2epro']->getValue();
        $this->assertSame('', $essM2epro['last_success_at']->getValue());
        $this->assertSame('2026-05-07 12:30:00', $essM2epro['next_scheduled_at']->getValue());
    }

    public function testNextScheduledPicksTheEarliestPendingRowPerJob(): void
    {
        $reporter = $this->reporterWithRows([
            $this->scheduleRow('cron_dm', 'pending', '2026-05-07 12:00:00', null, '2026-05-07 14:00:00'),
            $this->scheduleRow('cron_dm', 'pending', '2026-05-07 12:00:00', null, '2026-05-07 13:00:00'),
        ]);

        $jobs = $this->rowsByJobCode($reporter->getStatus()['jobs']->getRows());

        $this->assertSame('2026-05-07 13:00:00', $jobs['cron_dm']->getValue()['next_scheduled_at']->getValue());
    }
}
