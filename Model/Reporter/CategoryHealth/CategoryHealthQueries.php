<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter\CategoryHealth;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Raw-SQL category data-quality checks for CategoryHealthReporter.
 *
 * Category tree: catalog_category_entity.level 0 is the tree root (id 1), level 1 a store root
 * category, level 2+ a real category; "path" is the slash-separated id chain ('1/2/5/12').
 * Store views belong to a store group whose root_category_id names the root of "their" tree, so
 * a category belongs to a store view when its path starts with that root's path.
 *
 * EAV value tables (catalog_category_entity_int/varchar) are joined on the category's link field
 * (entity_id on Open Source, row_id on Adobe Commerce with content staging), resolved through
 * the MetadataPool; default scope (store_id = 0) values only. Tables that reference a category
 * by entity_id on both editions (url_rewrite, the category-product index) are joined on
 * entity_id.
 *
 * The category-product index is per store view in Magento 2.4
 * (catalog_category_product_index_store{id}); its table name is resolved via TableMaintainer.
 * A store view whose index table does not exist is skipped, since "empty" cannot be told apart
 * from "never built" there.
 */
class CategoryHealthQueries
{
    public const SAMPLE_LIMIT = 20;

    private const ENTITY_TYPE_CODE = 'catalog_category';

    /**
     * @var array<string,int>|null attribute code => attribute id
     */
    private ?array $attributeIds = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param MetadataPool $metadataPool
     * @param TableMaintainer $tableMaintainer
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly TableMaintainer $tableMaintainer
    ) {
    }

    /**
     * Store views (store_id > 0) whose store group root category exists, with the root's path.
     * Store views with a missing root are covered by storeRootProblems() instead.
     *
     * @return list<array{store_id: int, code: string, root_path: string}>
     */
    public function storeViews(): array
    {
        $connection = $this->connection();

        $select = $connection->select()
            ->from(['s' => $this->table('store')], ['store_id', 'code'])
            ->joinInner(['g' => $this->table('store_group')], 'g.group_id = s.group_id', [])
            ->joinInner(['root' => $this->table('catalog_category_entity')], 'root.entity_id = g.root_category_id' . $this->currentRow('root'), ['root_path' => 'path'])
            ->where('s.store_id > 0')
            ->where('s.is_active = 1')
            ->order('s.store_id ASC');

        return array_map(static fn (array $row): array => [
            'store_id' => (int) $row['store_id'],
            'code' => (string) $row['code'],
            'root_path' => (string) $row['root_path'],
        ], $connection->fetchAll($select));
    }

    /**
     * Active, in-menu categories (level >= 2) with no rows in the category-product index of a
     * store view they belong to. Each category appears once, attributed to the first store view
     * in which it is empty.
     *
     * @param list<array{store_id: int, code: string, root_path: string}> $storeViews
     * @return list<array{category_id: int, path: string, store: string}>
     */
    public function emptyCategories(array $storeViews): array
    {
        $connection = $this->connection();
        $found = [];

        foreach ($storeViews as $store) {
            $indexTable = $this->indexTable($store['store_id']);
            if (!$connection->isTableExists($indexTable)) {
                continue;
            }

            $select = $this->activeCategorySelect(true)
                ->columns(['e.entity_id', 'e.path'])
                ->where('e.path LIKE ?', $store['root_path'] . '/%')
                ->where(sprintf(
                    'NOT EXISTS (SELECT 1 FROM %s AS i WHERE i.category_id = e.entity_id)',
                    $connection->quoteIdentifier($indexTable)
                ))
                ->order('e.entity_id ASC');

            foreach ($connection->fetchAll($select) as $row) {
                $id = (int) $row['entity_id'];
                $found[$id] ??= ['category_id' => $id, 'path' => (string) $row['path'], 'store' => $store['code']];
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * Active categories (level >= 2) that have a disabled ancestor (also level >= 2) and so
     * cannot be reached through the storefront tree. Walks the tree in PHP over one query.
     *
     * @return list<array{category_id: int, path: string, ancestor_id: int}>
     */
    public function unreachableCategories(): array
    {
        $connection = $this->connection();

        $select = $connection->select()
            ->from(['e' => $this->table('catalog_category_entity')], ['e.entity_id', 'e.path'])
            ->joinInner(
                ['a' => $this->table('catalog_category_entity_int')],
                $this->eavJoin('a', 'is_active'),
                ['is_active' => 'value']
            )
            ->where('e.level >= 2')
            ->where($this->currentRow('e', true))
            ->order('e.entity_id ASC');

        $categories = [];
        foreach ($connection->fetchAll($select) as $row) {
            $categories[(int) $row['entity_id']] = ['path' => (string) $row['path'], 'active' => (int) $row['is_active'] === 1];
        }

        $unreachable = [];
        foreach ($categories as $id => $category) {
            if (!$category['active']) {
                continue;
            }
            $ancestors = array_map('intval', explode('/', $category['path']));
            foreach ($ancestors as $ancestorId) {
                if ($ancestorId !== $id && isset($categories[$ancestorId]) && !$categories[$ancestorId]['active']) {
                    $unreachable[] = ['category_id' => $id, 'path' => $category['path'], 'ancestor_id' => $ancestorId];
                    break;
                }
            }
        }

        return $unreachable;
    }

    /**
     * Active categories (level >= 2) under a store view's root with no category url_rewrite row
     * for that store view. A category can be counted once per store view.
     *
     * @param list<array{store_id: int, code: string, root_path: string}> $storeViews
     * @return array{count: int, samples: list<array{category_id: int, path: string, store: string}>}
     */
    public function missingUrlRewrites(array $storeViews): array
    {
        $connection = $this->connection();
        $count = 0;
        $samples = [];

        foreach ($storeViews as $store) {
            $base = fn (): \Magento\Framework\DB\Select => $this->activeCategorySelect(false)
                ->where('e.path LIKE ?', $store['root_path'] . '/%')
                ->where(sprintf(
                    'NOT EXISTS (SELECT 1 FROM %s AS u WHERE u.entity_type = %s AND u.entity_id = e.entity_id AND u.store_id = %d)',
                    $connection->quoteIdentifier($this->table('url_rewrite')),
                    $connection->quote('category'),
                    $store['store_id']
                ));

            $count += (int) $connection->fetchOne($base()->columns(['cnt' => new Expression('COUNT(*)')]));

            $remaining = self::SAMPLE_LIMIT - count($samples);
            if ($remaining > 0) {
                $rows = $connection->fetchAll(
                    $base()->columns(['e.entity_id', 'e.path'])->order('e.entity_id ASC')->limit($remaining)
                );
                foreach ($rows as $row) {
                    $samples[] = [
                        'category_id' => (int) $row['entity_id'],
                        'path' => (string) $row['path'],
                        'store' => $store['code'],
                    ];
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    /**
     * Store groups (group_id > 0) whose root category is missing or disabled.
     *
     * @return list<array{code: string, name: string, root_category_id: int, problem: string}>
     */
    public function storeRootProblems(): array
    {
        $connection = $this->connection();

        $select = $connection->select()
            ->from(['g' => $this->table('store_group')], ['code', 'name', 'root_category_id'])
            ->joinLeft(['root' => $this->table('catalog_category_entity')], 'root.entity_id = g.root_category_id' . $this->currentRow('root'), ['root_exists' => 'entity_id'])
            ->joinLeft(
                ['a' => $this->table('catalog_category_entity_int')],
                $this->eavJoin('a', 'is_active', 'root'),
                ['root_active' => 'value']
            )
            ->where('g.group_id > 0')
            ->order('g.group_id ASC');

        $problems = [];
        foreach ($connection->fetchAll($select) as $row) {
            $problem = null;
            if ($row['root_exists'] === null) {
                $problem = 'missing';
            } elseif ((int) $row['root_active'] !== 1) {
                $problem = 'disabled';
            }
            if ($problem !== null) {
                $problems[] = [
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'root_category_id' => (int) $row['root_category_id'],
                    'problem' => $problem,
                ];
            }
        }

        return $problems;
    }

    /**
     * Default-scope names for the given category ids, keyed by id.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    public function categoryNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $connection = $this->connection();
        $select = $connection->select()
            ->from(['e' => $this->table('catalog_category_entity')], ['e.entity_id'])
            ->joinInner(
                ['n' => $this->table('catalog_category_entity_varchar')],
                $this->eavJoin('n', 'name'),
                ['name' => 'value']
            )
            ->where('e.entity_id IN (?)', $ids)
            ->where($this->currentRow('e', true));

        $names = [];
        foreach ($connection->fetchAll($select) as $row) {
            $names[(int) $row['entity_id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Base select: catalog_category_entity AS e, level >= 2, default-scope is_active = 1, and
     * optionally include_in_menu = 1. Callers add columns and further conditions.
     *
     * @param bool $inMenuOnly
     */
    private function activeCategorySelect(bool $inMenuOnly): \Magento\Framework\DB\Select
    {
        $select = $this->connection()->select()
            ->from(['e' => $this->table('catalog_category_entity')], [])
            ->joinInner(['act' => $this->table('catalog_category_entity_int')], $this->eavJoin('act', 'is_active') . ' AND act.value = 1', [])
            ->where('e.level >= 2')
            ->where($this->currentRow('e', true));

        if ($inMenuOnly) {
            $select->joinInner(['menu' => $this->table('catalog_category_entity_int')], $this->eavJoin('menu', 'include_in_menu') . ' AND menu.value = 1', []);
        }

        return $select;
    }

    /**
     * Restricts to the current staging row on editions whose link field isn't entity_id
     * (Adobe Commerce content staging keeps several rows per entity_id); empty on Open Source.
     * Returned as " AND ..." for appending to a join condition, or bare when $bare is true
     * (a no-op "1 = 1" on Open Source).
     *
     * @param string $alias
     * @param bool $bare
     */
    private function currentRow(string $alias, bool $bare = false): string
    {
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        if ($linkField === 'entity_id') {
            return $bare ? '1 = 1' : '';
        }
        $condition = sprintf('%1$s.created_in <= UNIX_TIMESTAMP() AND %1$s.updated_in > UNIX_TIMESTAMP()', $alias);

        return $bare ? $condition : ' AND ' . $condition;
    }

    /**
     * Join condition for an EAV value table alias: link field, attribute id, default scope.
     *
     * @param string $alias
     * @param string $attributeCode
     * @param string $entityAlias
     */
    private function eavJoin(string $alias, string $attributeCode, string $entityAlias = 'e'): string
    {
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();

        return sprintf(
            '%1$s.%2$s = %3$s.%2$s AND %1$s.attribute_id = %4$d AND %1$s.store_id = 0',
            $alias,
            $linkField,
            $entityAlias,
            $this->attributeId($attributeCode)
        );
    }

    /**
     * Attribute id for a catalog_category attribute code (looked up once for all four).
     *
     * @param string $code
     */
    private function attributeId(string $code): int
    {
        if ($this->attributeIds === null) {
            $connection = $this->connection();
            $select = $connection->select()
                ->from(['a' => $this->table('eav_attribute')], ['attribute_code', 'attribute_id'])
                ->joinInner(['t' => $this->table('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
                ->where('t.entity_type_code = ?', self::ENTITY_TYPE_CODE)
                ->where('a.attribute_code IN (?)', ['name', 'is_active', 'include_in_menu']);

            $this->attributeIds = array_map('intval', $connection->fetchPairs($select));
        }

        return $this->attributeIds[$code] ?? 0;
    }

    /**
     * Resolved category-product index table for a store view.
     *
     * @param int $storeId
     */
    private function indexTable(int $storeId): string
    {
        return (string) $this->tableMaintainer->getMainTable($storeId);
    }

    /**
     * @param string $table
     */
    private function table(string $table): string
    {
        return $this->resourceConnection->getTableName($table);
    }

    /**
     * The default DB connection.
     */
    private function connection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}
