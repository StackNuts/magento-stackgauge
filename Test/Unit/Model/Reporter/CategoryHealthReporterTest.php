<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\CategoryHealth\CategoryHealthQueries;
use StackNuts\StackGauge\Model\Reporter\CategoryHealthReporter;

class CategoryHealthReporterTest extends TestCase
{
    /**
     * @return CategoryHealthQueries&MockObject
     */
    private function queries(): CategoryHealthQueries
    {
        $queries = $this->createMock(CategoryHealthQueries::class);
        $queries->method('storeViews')->willReturn([]);
        $queries->method('emptyCategories')->willReturn([]);
        $queries->method('unreachableCategories')->willReturn([]);
        $queries->method('missingUrlRewrites')->willReturn(['count' => 0, 'samples' => []]);
        $queries->method('storeRootProblems')->willReturn([]);
        $queries->method('categoryNames')->willReturn([]);

        return $queries;
    }

    private function reporter(CategoryHealthQueries $queries): CategoryHealthReporter
    {
        return new CategoryHealthReporter($queries, new Field(), new Section());
    }

    public function testEmptyCaseReportsZeroCountsAndEmptyTables(): void
    {
        $status = $this->reporter($this->queries())->getStatus();

        $this->assertSame(
            ['general', 'empty_categories', 'unreachable_categories', 'url_rewrite_missing', 'store_root_problems'],
            array_keys($status)
        );
        $fields = $status['general']->getFields();
        foreach (['empty_categories_count', 'unreachable_categories_count', 'url_rewrite_missing_count', 'store_root_problem_count'] as $key) {
            $this->assertSame(0, $fields[$key]->getValue(), $key);
        }
    }

    public function testPayloadShapeWithFindings(): void
    {
        $queries = $this->createMock(CategoryHealthQueries::class);
        $queries->method('storeViews')->willReturn([['store_id' => 1, 'code' => 'default', 'root_path' => '1/2']]);
        $queries->method('emptyCategories')->willReturn([
            ['category_id' => 5, 'path' => '1/2/5', 'store' => 'default'],
            ['category_id' => 12, 'path' => '1/2/5/12', 'store' => 'default'],
        ]);
        $queries->method('unreachableCategories')->willReturn([
            ['category_id' => 12, 'path' => '1/2/5/12', 'ancestor_id' => 5],
        ]);
        $queries->method('missingUrlRewrites')->willReturn([
            'count' => 7,
            'samples' => [['category_id' => 12, 'path' => '1/2/5/12', 'store' => 'default']],
        ]);
        $queries->method('storeRootProblems')->willReturn([
            ['code' => 'main', 'name' => 'Main', 'root_category_id' => 99, 'problem' => 'missing'],
        ]);
        $queries->method('categoryNames')->willReturn([5 => 'Shoes', 12 => 'Boots']);

        $status = $this->reporter($queries)->getStatus();
        $fields = $status['general']->getFields();

        $this->assertSame(2, $fields['empty_categories_count']->getValue());
        $this->assertSame(1, $fields['unreachable_categories_count']->getValue());
        $this->assertSame(7, $fields['url_rewrite_missing_count']->getValue());
        $this->assertSame(1, $fields['store_root_problem_count']->getValue());

        $json = json_encode($status);
        $this->assertStringContainsString('Shoes > Boots', (string) $json);
        $this->assertStringContainsString('Shoes (#5)', (string) $json);
        $this->assertStringContainsString('missing', (string) $json);
    }

    public function testMetricCatalog(): void
    {
        $reporter = $this->reporter($this->queries());
        $metrics = [];
        foreach ($reporter->getTrackableMetrics() as $definition) {
            $metrics[$definition->getMetricKey()] = $definition;
        }

        $this->assertSame(
            ['category_health.empty_categories_count', 'category_health.store_root_problem_count'],
            array_keys($metrics)
        );

        $empty = $metrics['category_health.empty_categories_count'];
        $this->assertSame(MetricDefinition::OPERATOR_GT, $empty->getDefaultOperator());
        $this->assertSame(5, $empty->getDefaultThreshold());
        $this->assertSame(1500, $empty->getDefaultWindowMinutes());
        $this->assertSame(MetricDefinition::SEVERITY_WARNING, $empty->getSeverity());

        $root = $metrics['category_health.store_root_problem_count'];
        $this->assertSame(MetricDefinition::OPERATOR_GT, $root->getDefaultOperator());
        $this->assertSame(0, $root->getDefaultThreshold());
        $this->assertSame(MetricDefinition::SEVERITY_CRITICAL, $root->getSeverity());
    }

    public function testDeclarations(): void
    {
        $reporter = $this->reporter($this->queries());

        $this->assertSame('category_health', $reporter->getName());
        $this->assertSame('catalog', $reporter->getSubject());
        $this->assertSame('daily', $reporter->getCadence());
        $this->assertSame('commerce', $reporter->getSection());
        $this->assertSame(0, $reporter->getSlot());
    }

    public function testMissingIndexTableSkipsStoreView(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        foreach (['from', 'joinInner', 'where', 'columns', 'order'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn(['is_active' => 46, 'include_in_menu' => 69, 'name' => 45]);
        $connection->method('isTableExists')->willReturn(false);
        // Must never be reached: a store view without an index table is skipped.
        $connection->expects($this->never())->method('fetchAll');

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->with(CategoryInterface::class)->willReturn($metadata);

        $maintainer = $this->createMock(TableMaintainer::class);
        $maintainer->method('getMainTable')->willReturn('catalog_category_product_index_store1');

        $queries = new CategoryHealthQueries($resource, $pool, $maintainer);

        $this->assertSame(
            [],
            $queries->emptyCategories([['store_id' => 1, 'code' => 'default', 'root_path' => '1/2']])
        );
    }

    public function testRowIdLinkFieldRestrictsToCurrentStagingRow(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        foreach (['from', 'joinInner', 'joinLeft', 'columns', 'order'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $wheres = [];
        $select->method('where')->willReturnCallback(function ($cond) use (&$wheres, $select) {
            $wheres[] = (string) $cond;

            return $select;
        });
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchPairs')->willReturn(['is_active' => 46]);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('row_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        $queries = new CategoryHealthQueries($resource, $pool, $this->createMock(TableMaintainer::class));
        $queries->unreachableCategories();

        $this->assertContains('e.created_in <= UNIX_TIMESTAMP() AND e.updated_in > UNIX_TIMESTAMP()', $wheres);
    }
}
