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
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use Throwable;

class IndexerReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use HealthSectionTrait;

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
     * Upper bound on the distinct-entity count read per indexer; far above the critical band.
     */
    private const BACKLOG_COUNT_CAP = 100000;

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

    /**
     * @param CollectionFactory $indexerCollectionFactory
     * @param ResourceConnection $resourceConnection
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the indexers reporter.
     */
    public function getName(): string
    {
        return 'indexers';
    }

    /**
     * Human-readable label for the indexers reporter block.
     */
    public function getLabel(): string
    {
        return 'Indexers';
    }

    /**
     * One-line summary of what the indexers reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Per-indexer status, mode, and (for schedule-mode indexers) pending changelog backlog.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports each indexer's status, mode, and schedule backlog, plus the worst backlog count across all of them.
     */
    public function getStatus(): array
    {
        $indexers = [];
        $maxBacklog = 0;

        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $scheduled = $indexer->isScheduled();
            $schedule = $scheduled ? $this->scheduleStatus($indexer) : ['label' => '', 'count' => 0, 'unavailable' => false];
            $maxBacklog = max($maxBacklog, $schedule['count']);

            $indexers[] = $this->field->array((string)$indexer->getId(), [
                'id' => $this->field->varchar('ID', (string)$indexer->getId()),
                'title' => $this->field->varchar('Title', (string)$indexer->getTitle()),
                'description' => $this->field->varchar('Description', (string)$indexer->getDescription()),
                'status' => $this->field->varchar(
                    'Status',
                    self::STATUS_LABELS[$indexer->getStatus()] ?? $indexer->getStatus(),
                    self::CRITICAL_STATUS_LABELS
                ),
                'mode' => $this->field->varchar('Mode', $scheduled ? 'schedule' : 'save'),
                'schedule_status' => $this->field->varchar(
                    'Schedule Status',
                    $schedule['label'],
                    severity: $scheduled
                        ? ($schedule['unavailable'] ? Field::SEVERITY_WARNING : $this->backlogSeverity($schedule['count']))
                        : null
                ),
                'updated_at' => $this->field->datetime('Updated At', (string)$indexer->getLatestUpdated()),
            ]);
        }

        return [
            'general' => $this->section->facts(
                'general',
                'General',
                'Worst pending changelog backlog across every schedule-mode indexer.',
                [
                    'max_backlog_count' => $this->field->trackableNumber(
                        'Max Backlog',
                        $maxBacklog,
                        self::METRIC_MAX_BACKLOG_COUNT,
                        MetricDefinition::AGGREGATION_LATEST
                    ),
                ]
            ),
            'indexers' => $this->section->table('indexers', 'Indexer Status', '', $indexers, keyName: 'id'),
        ];
    }

    /**
     * Alertable metric for the indexers reporter: worst pending changelog backlog across all indexers.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_MAX_BACKLOG_COUNT,
                'Indexers: Max Backlog',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                // A few hundred queued changes is a normal import or price update being worked through; only a
                // backlog this large means the indexer is falling behind rather than draining.
                1000,
                15,
                null,
                description: 'An indexer has {value} changes waiting, above the limit of {threshold}.',
                impact: 'The storefront may show stale prices, stock and catalogue data.',
                criticalThreshold: 20000
            ),
        ];
    }

    /**
     * "<idle|working|suspended> (<n> in backlog)". Counts distinct changed entity ids in the
     * pending version range, capped at BACKLOG_COUNT_CAP via a LIMIT inside the DISTINCT
     * subselect so a mass import can't build a backlog-sized temp table every hour per indexer.
     * A capped count is shown as "100000+" and reported as the cap.
     *
     * One indexer failing (e.g. a custom indexer whose changelog table doesn't exist) must not
     * blank out the others, so the failure is caught here - but surfaced as an "Unavailable"
     * label with a warning severity rather than a healthy-looking 0 backlog.
     *
     * @param IndexerInterface $indexer
     * @return array{label: string, count: int, unavailable: bool}
     */
    private function scheduleStatus(IndexerInterface $indexer): array
    {
        try {
            $view = $indexer->getView();
            $state = $view->getState()->loadByView($view->getId());
            $changelog = $view->getChangelog()->setViewId($view->getId());

            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName($changelog->getName());
            $distinct = $connection->select()
                ->distinct()
                ->from($table, [$changelog->getColumnName()])
                ->where('version_id > ?', $state->getVersionId())
                ->where('version_id <= ?', $changelog->getVersion())
                ->limit(self::BACKLOG_COUNT_CAP + 1);
            $backlog = (int)$connection->fetchOne(
                $connection->select()->from(['t' => $distinct], ['cnt' => new Expression('COUNT(*)')])
            );

            $capped = $backlog > self::BACKLOG_COUNT_CAP;
            $backlog = min($backlog, self::BACKLOG_COUNT_CAP);

            return [
                'label' => sprintf('%s (%d%s in backlog)', $state->getStatus(), $backlog, $capped ? '+' : ''),
                'count' => $backlog,
                'unavailable' => false,
            ];
        } catch (Throwable) {
            return ['label' => 'Unavailable (backlog could not be read)', 'count' => 0, 'unavailable' => true];
        }
    }

    /**
     * Maps a backlog count to a display severity using BACKLOG_WARNING_THRESHOLD/BACKLOG_CRITICAL_THRESHOLD.
     *
     * @param int $count
     */
    private function backlogSeverity(int $count): string
    {
        return match (true) {
            $count > self::BACKLOG_CRITICAL_THRESHOLD => Field::SEVERITY_CRITICAL,
            $count > self::BACKLOG_WARNING_THRESHOLD => Field::SEVERITY_WARNING,
            default => Field::SEVERITY_OK,
        };
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }
}
