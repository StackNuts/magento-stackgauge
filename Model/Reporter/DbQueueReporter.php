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
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;

/**
 * Per-queue backlog for every queue registered against Magento's own MySQL message-queue
 * adapter (the "db" connection) - a large, often-overlooked set even on a site that also has
 * RabbitMQ configured, since plenty of core modules (inventory reservations, media gallery
 * sync, mass attribute updates, ...) route their own consumers through "db" regardless of
 * what connection is configured for everything else. Complements RabbitMqReporter, which
 * only ever sees queues routed through the "amqp" connection.
 *
 * Status codes come from Magento\MysqlMq\Model\QueueManagement: NEW (2), IN_PROGRESS (3), and
 * RETRY_REQUIRED (5) are messages still waiting on a consumer - "backlog". ERROR (6) is
 * reported separately, since a pile of failed messages is a different, worse signal than a
 * queue that's merely behind. COMPLETE (4) and TO_BE_DELETED (7) are routinely cleaned up by
 * Magento's own consumers.cleanup cron and aren't counted at all.
 */
class DbQueueReporter implements ReporterInterface, DeclaresSectionInterface, MetricCatalogInterface
{
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_TOTAL_BACKLOG = 'db_queue.total_backlog';
    private const METRIC_TOTAL_ERRORS = 'db_queue.total_errors';

    private const QUEUE_TABLE = 'queue';
    private const QUEUE_MESSAGE_STATUS_TABLE = 'queue_message_status';

    private const STATUS_NEW = 2;
    private const STATUS_IN_PROGRESS = 3;
    private const STATUS_RETRY_REQUIRED = 5;
    private const STATUS_ERROR = 6;

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
     * Payload key for the DB queue reporter.
     */
    public function getName(): string
    {
        return 'db_queue';
    }

    /**
     * Human-readable label for the DB queue reporter block.
     */
    public function getLabel(): string
    {
        return 'DB Queue';
    }

    /**
     * One-line summary of what the DB queue reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Per-queue backlog and error count for every queue routed through the "db" message-queue connection.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports total backlog/errors plus a per-queue breakdown for every registered DB-backed queue.
     */
    public function getStatus(): array
    {
        $rows = $this->fetchQueueRows();
        $totalBacklog = array_sum(array_column($rows, 'backlog'));
        $totalErrors = array_sum(array_column($rows, 'errors'));

        return [
            'general' => $this->section->facts('general', 'General', '', [
                'queue_count' => $this->field->number('Queues', count($rows)),
                'total_backlog' => $this->field->trackableNumber(
                    'Total Backlog',
                    $totalBacklog,
                    self::METRIC_TOTAL_BACKLOG,
                    MetricDefinition::AGGREGATION_LATEST,
                    severity: $this->field->severityIf($totalBacklog > 0)
                ),
                'total_errors' => $this->field->trackableNumber(
                    'Total Errors',
                    $totalErrors,
                    self::METRIC_TOTAL_ERRORS,
                    MetricDefinition::AGGREGATION_LATEST,
                    severity: $this->field->severityIf($totalErrors > 0)
                ),
            ]),
            'queues' => $this->section->table(
                'queues',
                'Queues',
                'Per-queue backlog (new/in-progress/retry-required) and error count.',
                array_map(
                    fn (array $row) => $this->queueField(
                        (string)$row['name'],
                        (int)$row['backlog'],
                        (int)$row['errors']
                    ),
                    $rows
                )
            ),
        ];
    }

    /**
     * Alertable metrics for the DB queue reporter.
     *
     * Total backlog and total error count across every registered queue.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_TOTAL_BACKLOG,
                'DB Queue: Total Backlog',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                50,
                15,
                null,
                description: 'The database queue has {value} messages waiting, above the limit of {threshold}.',
                impact: 'Background jobs such as emails and imports are not completing.',
                criticalThreshold: 500
            ),
            // Threshold 0 - unlike backlog (expected to ebb and flow with normal traffic), any
            // error at all means a message a consumer genuinely couldn't process, worth
            // flagging regardless of how small the count is.
            new MetricDefinition(
                self::METRIC_TOTAL_ERRORS,
                'DB Queue: Total Errors',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                15,
                null,
                description: 'The database queue has {value} errored messages, above the limit of {threshold}.',
                impact: 'Background jobs are failing and will not be retried.'
            ),
        ];
    }

    /**
     * Every registered queue name with its backlog/error counts, 0 for a queue with no
     * outstanding messages at all. A missing table (the db message-queue adapter's own schema
     * not having been installed) is treated as "no queues". A query failure is deliberately NOT
     * caught: it propagates so ReporterPool reports the reporter as errored, rather than a
     * failed query reading as a healthy 0 backlog.
     *
     * The status filter is in the WHERE clause (not inside SUM(CASE...)), so the
     * (status, updated_at) index limits the scan to unfinished/failed rows instead of walking
     * every COMPLETE row kept for the cleanup window; queue names are merged in PHP.
     *
     * @return list<array{name: string, backlog: int, errors: int}>
     */
    private function fetchQueueRows(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName(self::QUEUE_TABLE);
        $statusTable = $this->resourceConnection->getTableName(self::QUEUE_MESSAGE_STATUS_TABLE);

        if (!$connection->isTableExists($queueTable) || !$connection->isTableExists($statusTable)) {
            return [];
        }

        $counts = [];
        $countSelect = $connection->select()
            ->from($statusTable, ['queue_id', 'status', 'cnt' => new Expression('COUNT(*)')])
            ->where('status IN (?)', [
                self::STATUS_NEW,
                self::STATUS_IN_PROGRESS,
                self::STATUS_RETRY_REQUIRED,
                self::STATUS_ERROR,
            ])
            ->group(['queue_id', 'status']);
        foreach ($connection->fetchAll($countSelect) as $row) {
            $queueId = (int)$row['queue_id'];
            $key = (int)$row['status'] === self::STATUS_ERROR ? 'errors' : 'backlog';
            $counts[$queueId][$key] = ($counts[$queueId][$key] ?? 0) + (int)$row['cnt'];
        }

        $rows = [];
        $queueSelect = $connection->select()->from($queueTable, ['id', 'name'])->order('name ASC');
        foreach ($connection->fetchAll($queueSelect) as $row) {
            $queueId = (int)$row['id'];
            $rows[] = [
                'name' => (string)$row['name'],
                'backlog' => $counts[$queueId]['backlog'] ?? 0,
                'errors' => $counts[$queueId]['errors'] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Builds one queue's row.
     *
     * @param string $name
     * @param int $backlog
     * @param int $errors
     */
    private function queueField(string $name, int $backlog, int $errors): ArrayField
    {
        return $this->field->array($name, [
            'name' => $this->field->varchar('Name', $name),
            'backlog' => $this->field->number(
                'Backlog',
                $backlog,
                severity: $this->field->severityIf($backlog > 0)
            ),
            'errors' => $this->field->number(
                'Errors',
                $errors,
                severity: $this->field->severityIf($errors > 0)
            ),
        ]);
    }
}
