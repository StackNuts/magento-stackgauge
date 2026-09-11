<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;
use Throwable;

class IndexerReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * The worst pending changelog backlog across every schedule-mode indexer - one site-wide
     * gauge rather than one metric per indexer, since the installed indexer set varies per
     * site/extension and MetricCatalogInterface needs a fixed, enumerable catalog.
     */
    private const METRIC_MAX_BACKLOG_COUNT = 'indexers.max_backlog_count';

    /**
     * Bands for the per-row "Schedule Status" display, matching the admin grid's own
     * severity bands.
     */
    private const BACKLOG_WARNING_THRESHOLD = 100;
    private const BACKLOG_CRITICAL_THRESHOLD = 1000;

    /**
     * Mirrors the admin grid's own "Status" column labels.
     *
     * The 'suspended' key is a literal string, not StateInterface::STATUS_SUSPENDED - that
     * constant doesn't exist before Adobe Commerce 2.4.7, and referencing it directly would
     * break autoloading of this class on older/Open Source installs.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        StateInterface::STATUS_VALID => 'Ready',
        StateInterface::STATUS_INVALID => 'Reindex required',
        StateInterface::STATUS_WORKING => 'Processing',
        'suspended' => 'Suspended',
    ];

    /**
     * Ready and Processing are both fine (Processing just means a reindex is actively
     * running) - only these two count as "something's wrong".
     *
     * @var list<string>
     */
    private const CRITICAL_STATUS_LABELS = ['Reindex required', 'Suspended'];

    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'indexers';
    }

    public function getLabel(): string
    {
        return 'Indexers';
    }

    public function getDescription(): string
    {
        return 'Per-indexer status, mode, and (for schedule-mode indexers) pending changelog backlog.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $indexers = [];
        $maxBacklog = 0;

        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $scheduled = $indexer->isScheduled();
            $schedule = $scheduled ? $this->scheduleStatus($indexer) : ['label' => '', 'count' => 0];
            $maxBacklog = max($maxBacklog, $schedule['count']);

            $indexers[] = Field::array((string)$indexer->getId(), [
                'id' => Field::varchar('ID', (string)$indexer->getId()),
                'title' => Field::varchar('Title', (string)$indexer->getTitle()),
                'description' => Field::varchar('Description', (string)$indexer->getDescription()),
                'status' => Field::varchar(
                    'Status',
                    self::STATUS_LABELS[$indexer->getStatus()] ?? $indexer->getStatus(),
                    self::CRITICAL_STATUS_LABELS
                ),
                'mode' => Field::varchar('Mode', $scheduled ? 'schedule' : 'save'),
                'schedule_status' => Field::varchar(
                    'Schedule Status',
                    $schedule['label'],
                    severity: $scheduled ? $this->backlogSeverity($schedule['count']) : null
                ),
                'updated_at' => Field::datetime('Updated At', (string)$indexer->getLatestUpdated()),
            ]);
        }

        return [
            'general' => Section::facts(
                'general',
                'General',
                'Worst pending changelog backlog across every schedule-mode indexer.',
                [
                    'max_backlog_count' => Field::trackableNumber(
                        'Max Backlog',
                        $maxBacklog,
                        self::METRIC_MAX_BACKLOG_COUNT,
                        MetricDefinition::AGGREGATION_LATEST
                    ),
                ]
            ),
            'indexers' => Section::table('indexers', 'Indexer Status', '', $indexers, keyName: 'id'),
        ];
    }

    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_MAX_BACKLOG_COUNT,
                'Indexers: Max Backlog',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                100,
                15
            ),
        ];
    }

    /**
     * "<idle|working|suspended> (<n> in backlog)". Uses a COUNT(DISTINCT) query rather than
     * loading every pending entity id into PHP, since this runs per indexer on every report.
     * Swallows failures (e.g. a custom indexer whose changelog table doesn't exist) so one
     * indexer's failure doesn't blank out the others.
     *
     * @return array{label: string, count: int}
     */
    private function scheduleStatus(IndexerInterface $indexer): array
    {
        try {
            $view = $indexer->getView();
            $state = $view->getState()->loadByView($view->getId());
            $changelog = $view->getChangelog()->setViewId($view->getId());

            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName($changelog->getName());
            $backlog = (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, ['cnt' => new Expression('COUNT(DISTINCT entity_id)')])
                    ->where('version_id > ?', $state->getVersionId())
                    ->where('version_id <= ?', $changelog->getVersion())
            );

            return ['label' => sprintf('%s (%d in backlog)', $state->getStatus(), $backlog), 'count' => $backlog];
        } catch (Throwable) {
            return ['label' => '', 'count' => 0];
        }
    }

    private function backlogSeverity(int $count): string
    {
        return match (true) {
            $count > self::BACKLOG_CRITICAL_THRESHOLD => Field::SEVERITY_CRITICAL,
            $count > self::BACKLOG_WARNING_THRESHOLD => Field::SEVERITY_WARNING,
            default => Field::SEVERITY_OK,
        };
    }
}
