<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter\ProductHealth;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Raw-SQL product data-quality checks for ProductHealthReporter. Every check is one SQL
 * statement over catalog_product_entity (alias "p"), counted or sampled (LIMIT 20, ordered by
 * entity_id).
 *
 * EAV value tables are joined on the product's link field (entity_id on Open Source, row_id on
 * Adobe Commerce with content staging), resolved through the MetadataPool; default scope
 * (store_id = 0) values only. Tables that reference the product by entity_id on both editions
 * (catalog_product_website, catalog_category_product, url_rewrite, and the *child* side of the
 * super/grouped/bundle link tables) are joined on p.entity_id; the *parent* side of those link
 * tables references the parent's link field.
 */
class ProductHealthQueries
{
    public const CHECK_MISSING_IMAGE = 'missing_image';
    public const CHECK_NO_PRICE = 'no_price';
    public const CHECK_MISSING_DESCRIPTION = 'missing_description';
    public const CHECK_MISSING_META_DESCRIPTION = 'missing_meta_description';
    public const CHECK_NO_WEBSITE = 'no_website';
    public const CHECK_NO_CATEGORY = 'no_category';
    public const CHECK_ORPHANED_NOT_VISIBLE = 'orphaned_not_visible';
    public const CHECK_COMPOSITE_UNBUYABLE = 'composite_unbuyable';
    public const CHECK_URL_REWRITE_MISSING = 'url_rewrite_missing';
    public const CHECK_DUPLICATE_URL_KEY = 'duplicate_url_key';
    public const CHECK_CONFIGURABLE_OUT_OF_STOCK = 'configurable_out_of_stock';

    public const SAMPLE_LIMIT = 20;
    private const SKU_LIST_MAX_LENGTH = 200;

    /**
     * @var array<string,int>|null attribute_code => attribute_id
     */
    private ?array $attributeIds = null;

    private ?string $linkField = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * The product link field used to join EAV value tables: entity_id, or row_id on Adobe Commerce staging.
     */
    public function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = (string) $this->metadataPool
                ->getMetadata(ProductInterface::class)
                ->getLinkField();
        }
        return $this->linkField;
    }

    /**
     * Number of rows matching the given check.
     */
    public function count(string $check): int
    {
        $connection = $this->resourceConnection->getConnection();

        if ($check === self::CHECK_DUPLICATE_URL_KEY) {
            $sql = 'SELECT COUNT(*) FROM (' . $this->duplicateUrlKeySql(false) . ') AS dup';
            return (int) $connection->fetchOne($sql);
        }

        $def = $this->definition($check);
        $sql = 'SELECT COUNT(*) FROM ' . $this->table('catalog_product_entity') . ' AS p '
            . implode(' ', $def['joins']) . ' WHERE ' . $this->baseWhere() . ' AND (' . $def['where'] . ')';

        return (int) $connection->fetchOne($sql);
    }

    /**
     * Up to SAMPLE_LIMIT rows for the given check: sku, name, type_id, plus the check's extra
     * column (if any) under the key returned by extraColumn(). Duplicate url_key rows are
     * url_key, products and skus instead.
     *
     * @return list<array<string,mixed>>
     */
    public function samples(string $check): array
    {
        $connection = $this->resourceConnection->getConnection();

        if ($check === self::CHECK_DUPLICATE_URL_KEY) {
            return array_values((array) $connection->fetchAll($this->duplicateUrlKeySql(true)));
        }

        $def = $this->definition($check);
        $nameJoin = $this->eavJoin('name', 'varchar');
        $extra = $def['extra'] !== null ? ', ' . $def['extra'] . ' AS extra' : '';

        $sql = 'SELECT p.sku AS sku, ' . $nameJoin['alias'] . '.value AS name, p.type_id AS type_id' . $extra
            . ' FROM ' . $this->table('catalog_product_entity') . ' AS p '
            . implode(' ', array_merge($def['joins'], [$nameJoin['join']]))
            . ' WHERE ' . $this->baseWhere() . ' AND (' . $def['where'] . ')'
            . ' ORDER BY p.entity_id LIMIT ' . self::SAMPLE_LIMIT;

        return array_values((array) $connection->fetchAll($sql));
    }

    /**
     * Key and label of the check-specific extra sample column, or null when the check has none.
     *
     * @return array{0:string,1:string}|null
     */
    public function extraColumn(string $check): ?array
    {
        return match ($check) {
            self::CHECK_COMPOSITE_UNBUYABLE => ['reason', 'Reason'],
            self::CHECK_URL_REWRITE_MISSING => ['store', 'Store Views'],
            self::CHECK_CONFIGURABLE_OUT_OF_STOCK => ['enabled_children', 'Enabled Children'],
            default => null,
        };
    }

    /**
     * Joins, WHERE fragment and optional extra select expression for a non-aggregate check.
     *
     * @return array{joins:list<string>,where:string,extra:?string}
     */
    private function definition(string $check): array
    {
        $status = $this->eavJoin('status', 'int');
        $visibility = $this->eavJoin('visibility', 'int');
        $storefrontJoins = [$status['join'], $visibility['join']];
        $enabled = 'COALESCE(' . $status['alias'] . '.value, 0) = 1';
        $storefront = $enabled
            . ' AND COALESCE(' . $visibility['alias'] . '.value, 1) IN (2, 3, 4) AND ' . $this->inWebsite();

        switch ($check) {
            case self::CHECK_MISSING_IMAGE:
                $image = $this->eavJoin('image', 'varchar');
                return [
                    'joins' => array_merge($storefrontJoins, [$image['join']]),
                    'where' => $storefront . " AND COALESCE({$image['alias']}.value, '') IN ('', 'no_selection')",
                    'extra' => null,
                ];
            case self::CHECK_NO_PRICE:
                $price = $this->eavJoin('price', 'decimal');
                return [
                    'joins' => [$status['join'], $price['join']],
                    'where' => $enabled . " AND p.type_id IN ('simple', 'virtual', 'downloadable')"
                        . " AND ({$price['alias']}.value IS NULL OR {$price['alias']}.value <= 0)",
                    'extra' => null,
                ];
            case self::CHECK_MISSING_DESCRIPTION:
            case self::CHECK_MISSING_META_DESCRIPTION:
                $code = $check === self::CHECK_MISSING_DESCRIPTION ? 'description' : 'meta_description';
                $type = $check === self::CHECK_MISSING_DESCRIPTION ? 'text' : 'varchar';
                $attr = $this->eavJoin($code, $type);
                return [
                    'joins' => array_merge($storefrontJoins, [$attr['join']]),
                    'where' => $storefront . " AND TRIM(COALESCE({$attr['alias']}.value, '')) = ''",
                    'extra' => null,
                ];
            case self::CHECK_NO_WEBSITE:
                return [
                    'joins' => [$status['join']],
                    'where' => $enabled . ' AND NOT ' . $this->inWebsite(),
                    'extra' => null,
                ];
            case self::CHECK_NO_CATEGORY:
                return [
                    'joins' => $storefrontJoins,
                    'where' => $storefront . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('catalog_category_product')
                        . ' AS cp WHERE cp.product_id = p.entity_id)',
                    'extra' => null,
                ];
            case self::CHECK_ORPHANED_NOT_VISIBLE:
                return [
                    'joins' => $storefrontJoins,
                    'where' => $enabled . ' AND ' . $visibility['alias'] . '.value = 1'
                        . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('catalog_product_super_link')
                        . ' AS sl WHERE sl.product_id = p.entity_id)'
                        . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('catalog_product_link') . ' AS pl JOIN '
                        . $this->table('catalog_product_link_type') . " AS lt ON lt.link_type_id = pl.link_type_id"
                        . " AND lt.code = 'super' WHERE pl.linked_product_id = p.entity_id)"
                        . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('catalog_product_bundle_selection')
                        . ' AS bs WHERE bs.product_id = p.entity_id)',
                    'extra' => null,
                ];
            case self::CHECK_COMPOSITE_UNBUYABLE:
                $reason = $this->compositeReasonSql();
                return [
                    'joins' => $storefrontJoins,
                    'where' => $storefront . ' AND ' . $reason . ' IS NOT NULL',
                    'extra' => $reason,
                ];
            case self::CHECK_CONFIGURABLE_OUT_OF_STOCK:
                $children = $this->enabledChildrenSql(false);
                return [
                    'joins' => $storefrontJoins,
                    'where' => $storefront . " AND p.type_id = 'configurable' AND {$children} > 0"
                        . ' AND NOT EXISTS (' . $this->enabledChildrenSql(true) . ')',
                    'extra' => $children,
                ];
            case self::CHECK_URL_REWRITE_MISSING:
                $stores = $this->storesMissingRewriteSql();
                return [
                    'joins' => $storefrontJoins,
                    'where' => $storefront . ' AND ' . $stores . ' IS NOT NULL',
                    'extra' => $stores,
                ];
        }

        throw new \InvalidArgumentException(sprintf('Unknown product health check "%s".', $check));
    }

    /**
     * CASE expression yielding why a composite product has nothing purchasable, or NULL when it is fine.
     */
    private function compositeReasonSql(): string
    {
        $link = $this->getLinkField();
        $statusTable = $this->table('catalog_product_entity_int');
        $statusId = $this->attributeId('status');
        $superLink = $this->table('catalog_product_super_link');
        $entity = $this->table('catalog_product_entity');

        return "(CASE"
            . " WHEN p.type_id = 'configurable' AND NOT EXISTS (SELECT 1 FROM "
            . $this->table('catalog_product_super_attribute') . " AS sa WHERE sa.product_id = p.{$link})"
            . " THEN 'No configurable attributes'"
            . " WHEN p.type_id = 'configurable' AND NOT EXISTS (SELECT 1 FROM {$superLink} AS sl"
            . " WHERE sl.parent_id = p.{$link}) THEN 'No child products'"
            . " WHEN p.type_id = 'configurable' AND NOT EXISTS (SELECT 1 FROM {$superLink} AS sl"
            . " JOIN {$entity} AS c ON c.entity_id = sl.product_id"
            . " JOIN {$statusTable} AS cs ON cs.{$link} = c.{$link} AND cs.attribute_id = {$statusId}"
            . " AND cs.store_id = 0 AND cs.value = 1 WHERE sl.parent_id = p.{$link})"
            . " THEN 'All child products disabled'"
            . " WHEN p.type_id = 'grouped' AND NOT EXISTS (SELECT 1 FROM " . $this->table('catalog_product_link')
            . " AS gl JOIN " . $this->table('catalog_product_link_type') . " AS glt"
            . " ON glt.link_type_id = gl.link_type_id AND glt.code = 'super' WHERE gl.product_id = p.{$link})"
            . " THEN 'No linked products'"
            . " WHEN p.type_id = 'bundle' AND NOT EXISTS (SELECT 1 FROM " . $this->table('catalog_product_bundle_option')
            . " AS bo WHERE bo.parent_id = p.{$link}) THEN 'No bundle options'"
            . " WHEN p.type_id = 'bundle' AND EXISTS (SELECT 1 FROM " . $this->table('catalog_product_bundle_option')
            . " AS bo2 WHERE bo2.parent_id = p.{$link} AND bo2.required = 1 AND NOT EXISTS (SELECT 1 FROM "
            . $this->table('catalog_product_bundle_selection') . " AS bs WHERE bs.option_id = bo2.option_id))"
            . " THEN 'Required option has no selections'"
            . " ELSE NULL END)";
    }

    /**
     * Correlated subquery over a configurable's enabled children: their count, or (inStockOnly) the in-stock rows.
     * Stock is read from the default stock only (cataloginventory_stock_status, website_id 0, stock_id 1); a
     * child with no row there counts as out of stock. Custom MSI stocks (inventory_stock_N) are not read, so
     * multi-stock MSI stores may see false hits.
     */
    private function enabledChildrenSql(bool $inStockOnly): string
    {
        $link = $this->getLinkField();
        $from = $this->table('catalog_product_super_link') . ' AS sl JOIN ' . $this->table('catalog_product_entity')
            . ' AS c ON c.entity_id = sl.product_id JOIN ' . $this->table('catalog_product_entity_int')
            . " AS cs ON cs.{$link} = c.{$link} AND cs.attribute_id = " . $this->attributeId('status')
            . ' AND cs.store_id = 0 AND cs.value = 1';
        if ($inStockOnly) {
            return "SELECT 1 FROM {$from} JOIN " . $this->table('cataloginventory_stock_status')
                . ' AS ss ON ss.product_id = c.entity_id AND ss.website_id = 0 AND ss.stock_id = 1'
                . " AND ss.stock_status = 1 WHERE sl.parent_id = p.{$link}";
        }
        return "(SELECT COUNT(*) FROM {$from} WHERE sl.parent_id = p.{$link})";
    }

    /**
     * Correlated subquery listing active store-view codes (of the product's websites) lacking a canonical URL rewrite; NULL if none.
     */
    private function storesMissingRewriteSql(): string
    {
        return '(SELECT GROUP_CONCAT(DISTINCT s.code ORDER BY s.code SEPARATOR \', \') FROM '
            . $this->table('store') . ' AS s JOIN ' . $this->table('catalog_product_website')
            . ' AS w ON w.website_id = s.website_id AND w.product_id = p.entity_id WHERE s.store_id > 0 AND s.is_active = 1'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('url_rewrite') . " AS ur WHERE ur.entity_type = 'product'"
            . ' AND ur.entity_id = p.entity_id AND ur.store_id = s.store_id AND ur.metadata IS NULL))';
    }

    /**
     * EXISTS predicate: the product is assigned to at least one website.
     */
    private function inWebsite(): string
    {
        return 'EXISTS (SELECT 1 FROM ' . $this->table('catalog_product_website')
            . ' AS pw WHERE pw.product_id = p.entity_id)';
    }

    /**
     * Restricts to current product rows on staging editions; a no-op on Open Source.
     */
    private function baseWhere(): string
    {
        if ($this->getLinkField() === 'entity_id') {
            return '1 = 1';
        }
        return 'p.created_in <= UNIX_TIMESTAMP() AND p.updated_in > UNIX_TIMESTAMP()';
    }

    /**
     * Products sharing a default-scope url_key: url_key, product count, capped comma-joined skus.
     */
    private function duplicateUrlKeySql(bool $forSamples): string
    {
        $link = $this->getLinkField();
        $sql = 'SELECT v.value AS url_key, COUNT(DISTINCT p.entity_id) AS products, SUBSTRING(GROUP_CONCAT(DISTINCT p.sku'
            . ' ORDER BY p.entity_id SEPARATOR \', \'), 1, ' . self::SKU_LIST_MAX_LENGTH . ') AS skus FROM '
            . $this->table('catalog_product_entity_varchar') . " AS v JOIN " . $this->table('catalog_product_entity')
            . " AS p ON p.{$link} = v.{$link} WHERE v.attribute_id = " . $this->attributeId('url_key')
            . " AND v.store_id = 0 AND v.value <> '' AND " . $this->baseWhere()
            . ' GROUP BY v.value HAVING COUNT(DISTINCT p.entity_id) > 1';

        return $forSamples ? $sql . ' ORDER BY products DESC, v.value LIMIT ' . self::SAMPLE_LIMIT : $sql;
    }

    /**
     * LEFT JOIN of one default-scope EAV value table for an attribute, with its alias.
     *
     * @return array{alias:string,join:string}
     */
    private function eavJoin(string $code, string $type): array
    {
        $alias = 'v_' . $code;
        $link = $this->getLinkField();
        return [
            'alias' => $alias,
            'join' => 'LEFT JOIN ' . $this->table('catalog_product_entity_' . $type) . " AS {$alias}"
                . " ON {$alias}.{$link} = p.{$link} AND {$alias}.attribute_id = " . $this->attributeId($code)
                . " AND {$alias}.store_id = 0",
        ];
    }

    /**
     * catalog_product attribute id for a code, looked up once (0 if the attribute does not exist).
     */
    private function attributeId(string $code): int
    {
        if ($this->attributeIds === null) {
            $codes = ['status', 'visibility', 'image', 'price', 'description', 'meta_description', 'name', 'url_key'];
            $quoted = implode(', ', array_map(static fn (string $c): string => "'" . $c . "'", $codes));
            $sql = 'SELECT a.attribute_code, a.attribute_id FROM ' . $this->table('eav_attribute') . ' AS a JOIN '
                . $this->table('eav_entity_type') . " AS t ON t.entity_type_id = a.entity_type_id"
                . " WHERE t.entity_type_code = 'catalog_product' AND a.attribute_code IN ({$quoted})";
            $this->attributeIds = array_map(
                'intval',
                (array) $this->resourceConnection->getConnection()->fetchPairs($sql)
            );
        }
        return $this->attributeIds[$code] ?? 0;
    }

    /**
     * Prefixed, backtick-quoted table name.
     */
    private function table(string $name): string
    {
        return '`' . $this->resourceConnection->getTableName($name) . '`';
    }
}
