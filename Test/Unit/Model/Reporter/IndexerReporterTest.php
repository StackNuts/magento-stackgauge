<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\View\StateInterface as MviewStateInterface;
use Magento\Framework\Mview\ViewInterface;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\Indexer\Collection;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\IndexerReporter;

class IndexerReporterTest extends TestCase
{
    private function indexer(
        string $id,
        string $title,
        string $description,
        string $status,
        string $updatedAt,
        bool $scheduled = false
    ): Indexer&MockObject {
        $indexer = $this->createMock(Indexer::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getTitle')->willReturn($title);
        $indexer->method('getDescription')->willReturn($description);
        $indexer->method('getStatus')->willReturn($status);
        $indexer->method('isScheduled')->willReturn($scheduled);
        $indexer->method('getLatestUpdated')->willReturn($updatedAt);

        return $indexer;
    }

    private function reporter(Indexer $indexer, ?ResourceConnection $resourceConnection = null): IndexerReporter
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$indexer]);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new IndexerReporter($factory, $resourceConnection ?? $this->createMock(ResourceConnection::class));
    }

    public function testReportsDescriptionAndReadyStatusWithoutDuplicatingTitle(): void
    {
        $indexer = $this->indexer(
            'catalog_product_price',
            'Product Price',
            'Index product prices',
            StateInterface::STATUS_VALID,
            '2026-09-02 17:38:11'
        );

        $fields = $this->reporter($indexer)->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertArrayNotHasKey('name', $fields);
        $this->assertSame('Product Price', $fields['title']->getValue());
        $this->assertSame('Index product prices', $fields['description']->getValue());
        $this->assertSame('Ready', $fields['status']->getValue());
        $this->assertSame('save', $fields['mode']->getValue());
        $this->assertSame('', $fields['schedule_status']->getValue());
        $this->assertSame('datetime', $fields['updated_at']->getType());
        $this->assertSame('2026-09-02 17:38:11', $fields['updated_at']->getValue());
    }

    public function testAnInvalidIndexerReportsReindexRequired(): void
    {
        $indexer = $this->indexer(
            'cataloginventory_stock',
            'Stock',
            'Reindex stock status',
            StateInterface::STATUS_INVALID,
            '2026-09-02 17:38:11'
        );

        $fields = $this->reporter($indexer)->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertSame('Reindex required', $fields['status']->getValue());
    }

    public function testStatusFieldMarksOnlyReindexRequiredAndSuspendedAsCritical(): void
    {
        $indexer = $this->indexer(
            'catalog_product_price',
            'Product Price',
            '',
            StateInterface::STATUS_VALID,
            '2026-09-02 17:38:11'
        );

        $fields = $this->reporter($indexer)->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertSame(
            ['Reindex required', 'Suspended'],
            $fields['status']->jsonSerialize()['critical_values']
        );
    }

    /**
     * @return array{0: Indexer&MockObject, 1: ResourceConnection&MockObject}
     */
    private function scheduledIndexerWithBacklog(int $backlog): array
    {
        $indexer = $this->indexer(
            'catalogsearch_fulltext',
            'Catalog Search',
            'Rebuild search index',
            StateInterface::STATUS_VALID,
            '2026-09-02 17:38:11',
            scheduled: true
        );

        $mviewState = $this->createMock(MviewStateInterface::class);
        $mviewState->method('loadByView')->willReturnSelf();
        $mviewState->method('getStatus')->willReturn(MviewStateInterface::STATUS_IDLE);
        $mviewState->method('getVersionId')->willReturn('10');

        $changelog = $this->createMock(ChangelogInterface::class);
        $changelog->method('setViewId')->willReturnSelf();
        $changelog->method('getVersion')->willReturn(15);
        $changelog->method('getName')->willReturn('catalogsearch_fulltext_cl');

        $view = $this->createMock(ViewInterface::class);
        $view->method('getId')->willReturn('catalogsearch_fulltext');
        $view->method('getState')->willReturn($mviewState);
        $view->method('getChangelog')->willReturn($changelog);

        $indexer->method('getView')->willReturn($view);

        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn((string) $backlog);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('catalogsearch_fulltext_cl');

        return [$indexer, $resourceConnection];
    }

    public function testAScheduledIndexerReportsScheduleStatusWithBacklogCount(): void
    {
        [$indexer, $resourceConnection] = $this->scheduledIndexerWithBacklog(5);

        $status = $this->reporter($indexer, $resourceConnection)->getStatus();
        $fields = $status['indexers']->getRows()[0]->getValue();

        $this->assertSame('idle (5 in backlog)', $fields['schedule_status']->getValue());
        $this->assertSame(5, $status['general']->getFields()['max_backlog_count']->getValue());
    }

    public function testScheduleStatusSeverityIsOkAtOrBelowTheWarningThreshold(): void
    {
        [$indexer, $resourceConnection] = $this->scheduledIndexerWithBacklog(100);

        $fields = $this->reporter($indexer, $resourceConnection)->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertSame('ok', $fields['schedule_status']->jsonSerialize()['severity']);
    }

    public function testScheduleStatusSeverityIsWarningPastTheWarningThreshold(): void
    {
        [$indexer, $resourceConnection] = $this->scheduledIndexerWithBacklog(500);

        $fields = $this->reporter($indexer, $resourceConnection)->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertSame('warning', $fields['schedule_status']->jsonSerialize()['severity']);
    }

    public function testScheduleStatusSeverityIsCriticalPastTheCriticalThreshold(): void
    {
        [$indexer, $resourceConnection] = $this->scheduledIndexerWithBacklog(1500);

        $fields = $this->reporter($indexer, $resourceConnection)->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertSame('critical', $fields['schedule_status']->jsonSerialize()['severity']);
    }

    public function testAnUnscheduledIndexersScheduleStatusHasNoSeverity(): void
    {
        $indexer = $this->indexer(
            'catalog_product_price',
            'Product Price',
            '',
            StateInterface::STATUS_VALID,
            '2026-09-02 17:38:11'
        );

        $fields = $this->reporter($indexer)->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertArrayNotHasKey('severity', $fields['schedule_status']->jsonSerialize());
    }

    public function testMaxBacklogIsZeroWhenNothingIsScheduled(): void
    {
        $indexer = $this->indexer(
            'catalog_product_price',
            'Product Price',
            'Index product prices',
            StateInterface::STATUS_VALID,
            '2026-09-02 17:38:11'
        );

        $status = $this->reporter($indexer)->getStatus();

        $this->assertSame(0, $status['general']->getFields()['max_backlog_count']->getValue());
    }

    public function testDeclaresTheMaxBacklogAsATrackableMetric(): void
    {
        $reporter = $this->reporter($this->indexer('a', 'A', '', StateInterface::STATUS_VALID, '2026-09-02 17:38:11'));

        $metrics = $reporter->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('indexers.max_backlog_count', $metrics[0]->getMetricKey());
    }
}
