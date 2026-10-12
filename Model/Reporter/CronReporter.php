<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
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
class CronReporter implements ReporterInterface, DeclaresSectionInterface, DeclaresSlotInterface
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

    /**
     * @param CollectionFactory $scheduleCollectionFactory
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly CollectionFactory $scheduleCollectionFactory,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the cron reporter.
     */
    public function getName(): string
    {
        return 'cron';
    }

    /**
     * Human-readable label for the cron reporter block.
     */
    public function getLabel(): string
    {
        return 'Cron';
    }

    /**
     * One-line summary of what the cron reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Whether cron looks alive, plus last success and next due time per job code.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports whether cron looks alive from the most recent cron_schedule row, plus last success and next due time per job code.
     */
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
            $jobRows[] = $this->field->array($jobCode, [
                'job_code' => $this->field->varchar('Job', $jobCode),
                'last_success_at' => $this->field->varchar('Last Success', $lastSuccessByJob[$jobCode] ?? ''),
                'next_scheduled_at' => $this->field->varchar(
                    'Next Scheduled',
                    $nextScheduledByJob[$jobCode] ?? ''
                ),
            ]);
        }

        return [
            'general' => $this->section->facts(
                'general',
                'General',
                'Whether cron looks alive, and when the schedule was last generated.',
                [
                    'alive' => $this->field->bool('Alive', $alive, criticalWhen: false),
                    'last_schedule_generated_at' => $this->field->varchar(
                        'Last Schedule Generated At',
                        $mostRecentCreatedAt ?? ''
                    ),
                ]
            ),
            'jobs' => $this->section->table(
                'jobs',
                'Jobs',
                'Per-job last success and next scheduled time.',
                $jobRows,
                keyName: 'job_code'
            ),
        ];
    }

    /**
     * Run 2 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 2;
    }
}
