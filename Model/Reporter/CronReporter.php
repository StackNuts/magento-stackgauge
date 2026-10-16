<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
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
 *
 * Reads three small grouped/PK queries instead of a bounded window of rows, so every job is
 * covered however many rows it has (a fixed 500-row window was mostly pending rows and lost
 * hourly/daily jobs). Note "last success" can only look back as far as Magento keeps success
 * rows (cron history cleanup, 60 minutes by default), so a less-frequent job may legitimately
 * show no last success.
 *
 * "Alive" here is derived from when cron last *generated* schedule rows, which only cron
 * itself does: when this reporter runs from the cron-driven send, alive=false can't normally be
 * observed (a dead cron doesn't run it) - it shows up from a manual "Send Now". The real
 * "cron has stopped" signal is the heartbeat (cron_alive on the dashboard's site record), not
 * this field.
 */
class CronReporter implements ReporterInterface, DeclaresSectionInterface, DeclaresSubjectInterface, DeclaresSlotInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.1';
    private const ALIVE_THRESHOLD_MINUTES = 30;

    /**
     * @param ResourceConnection $resourceConnection
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
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
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');

        // cron_schedule's timestamps are always UTC strings; the datetime fields below carry
        // them through unchanged.
        $lastSuccessByJob = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['job_code', 'last_success' => new Expression('MAX(finished_at)')])
                ->where('status = ?', 'success')
                ->group('job_code')
        );
        $nextScheduledByJob = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['job_code', 'next_scheduled' => new Expression('MIN(scheduled_at)')])
                ->where('status = ?', 'pending')
                ->group('job_code')
        );
        // schedule_id is monotonic, so the newest row by primary key is the most recently generated.
        $newestCreatedAt = $connection->fetchOne(
            $connection->select()->from($table, ['created_at'])->order('schedule_id DESC')->limit(1)
        );
        $mostRecentCreatedAt = is_scalar($newestCreatedAt) && (string)$newestCreatedAt !== ''
            ? (string)$newestCreatedAt
            : null;

        // Appending " UTC" pins strtotime()'s interpretation regardless of PHP's ambient
        // default timezone (see Util\Clock).
        $alive = $mostRecentCreatedAt !== null
            && (time() - strtotime($mostRecentCreatedAt.' UTC')) <= self::ALIVE_THRESHOLD_MINUTES * 60;

        $jobCodes = array_unique(array_merge(array_keys($lastSuccessByJob), array_keys($nextScheduledByJob)));
        sort($jobCodes);

        $jobRows = [];
        foreach ($jobCodes as $jobCode) {
            $jobCode = (string)$jobCode;
            $jobRows[] = $this->field->array($jobCode, [
                'job_code' => $this->field->varchar('Job', $jobCode),
                'last_success_at' => $this->field->datetime(
                    'Last Success',
                    (string)($lastSuccessByJob[$jobCode] ?? '')
                ),
                'next_scheduled_at' => $this->field->datetime(
                    'Next Scheduled',
                    (string)($nextScheduledByJob[$jobCode] ?? '')
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
                    'last_schedule_generated_at' => $this->field->datetime(
                        'Last Schedule Generated At',
                        $mostRecentCreatedAt ?? ''
                    ),
                ]
            ),
            'jobs' => $this->section->table(
                'jobs',
                'Jobs',
                'Per-job last success and next scheduled time (UTC).',
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

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'cron';
    }
}
