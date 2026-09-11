<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;

/**
 * "Alive" plus, per job code, when it last succeeded and when it's next due. Grouped by
 * job_code rather than crontab.xml group, since cron_schedule rows don't record the group.
 * "Next due" comes from the earliest still-pending schedule row per job rather than parsing
 * the job's cron expression; if cron has stopped running, that row's scheduled_at is simply
 * in the past, and the dashboard (not this reporter) decides what counts as overdue.
 */
class CronReporter implements ReporterInterface, DeclaresSectionInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const ALIVE_THRESHOLD_MINUTES = 30;

    /**
     * Recent window rather than the whole history table - cron_schedule can carry a long
     * tail of old rows on a busy store, and only recent activity is relevant to "is this
     * running right now."
     */
    private const ROW_LIMIT = 500;

    public function __construct(
        private readonly CollectionFactory $scheduleCollectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'cron';
    }

    public function getLabel(): string
    {
        return 'Cron';
    }

    public function getDescription(): string
    {
        return 'Whether cron looks alive, plus last success and next due time per job code.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $collection = $this->scheduleCollectionFactory->create();
        $collection->setOrder('schedule_id', 'DESC')
            ->setPageSize(self::ROW_LIMIT);

        $mostRecentCreatedAt = null;
        $lastSuccessByJob = [];
        $nextScheduledByJob = [];

        foreach ($collection as $schedule) {
            $createdAt = $schedule->getCreatedAt();
            if ($createdAt !== null && ($mostRecentCreatedAt === null || $createdAt > $mostRecentCreatedAt)) {
                $mostRecentCreatedAt = $createdAt;
            }

            $jobCode = $schedule->getJobCode();

            if ($schedule->getStatus() === 'success') {
                $finishedAt = $schedule->getFinishedAt();
                if ($finishedAt !== null
                    && (!isset($lastSuccessByJob[$jobCode]) || $finishedAt > $lastSuccessByJob[$jobCode])
                ) {
                    $lastSuccessByJob[$jobCode] = $finishedAt;
                }
            }

            if ($schedule->getStatus() === 'pending') {
                $scheduledAt = $schedule->getScheduledAt();
                if ($scheduledAt !== null
                    && (!isset($nextScheduledByJob[$jobCode]) || $scheduledAt < $nextScheduledByJob[$jobCode])
                ) {
                    $nextScheduledByJob[$jobCode] = $scheduledAt;
                }
            }
        }

        // cron_schedule's timestamps are always UTC; appending " UTC" pins strtotime()'s
        // interpretation regardless of PHP's ambient default timezone (see Util\Clock).
        $alive = $mostRecentCreatedAt !== null
            && (time() - strtotime($mostRecentCreatedAt.' UTC')) <= self::ALIVE_THRESHOLD_MINUTES * 60;

        $jobCodes = array_unique(array_merge(array_keys($lastSuccessByJob), array_keys($nextScheduledByJob)));

        $jobRows = [];
        foreach ($jobCodes as $jobCode) {
            $jobRows[] = Field::array($jobCode, [
                'job_code' => Field::varchar('Job', $jobCode),
                'last_success_at' => Field::varchar('Last Success', $lastSuccessByJob[$jobCode] ?? ''),
                'next_scheduled_at' => Field::varchar('Next Scheduled', $nextScheduledByJob[$jobCode] ?? ''),
            ]);
        }

        return [
            'general' => Section::facts('general', 'General', 'Whether cron looks alive, and when the schedule was last generated.', [
                'alive' => Field::bool('Alive', $alive, criticalWhen: false),
                'last_schedule_generated_at' => Field::varchar('Last Schedule Generated At', $mostRecentCreatedAt ?? ''),
            ]),
            'jobs' => Section::table('jobs', 'Jobs', 'Per-job last success and next scheduled time.', $jobRows, keyName: 'job_code'),
        ];
    }
}
