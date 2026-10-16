<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter\Pricing;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Raw-SQL pricing checks for PricingReporter.
 *
 * EAV value tables are joined on the product's link field (entity_id on Open Source, row_id on
 * Adobe Commerce with content staging), resolved through the MetadataPool; default scope
 * (store_id = 0) values only. catalog_product_index_price and sales_order_item reference the
 * product by entity_id on both editions, so those are joined through catalog_product_entity.
 * Sample lists are capped at SAMPLE_LIMIT; counts are exact.
 */
class PricingQueries
{
    public const SAMPLE_LIMIT = 20;

    /**
     * Product types whose index rows are real prices rather than composite aggregates.
     */
    private const SIMPLE_TYPES = ['simple', 'virtual', 'downloadable'];

    /**
     * Rows fetched before de-duplicating to one row per product (a product has one index row
     * per customer group and website).
     */
    private const DEDUPE_FETCH_LIMIT = 500;

    /**
     * @var array<string,int>
     */
    private array $attributeIds = [];

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
     * Enabled simple/virtual/downloadable products with an index row discounted deeper than $ratio.
     *
     * @param float $ratio
     * @return array{count: int, rows: list<array<string,mixed>>}
     */
    public function deepDiscount(float $ratio): array
    {
        $where = sprintf('i.price > 0 AND i.final_price < i.price * (1 - %F)', $ratio);
        $discount = '(i.price - i.final_price) / i.price * 100';

        return $this->indexPriceCheck($where, $discount . ' DESC', $discount);
    }

    /**
     * Enabled simple/virtual/downloadable products with an index row priced at zero or below.
     *
     * @return array{count: int, rows: list<array<string,mixed>>}
     */
    public function zeroFinalPrice(): array
    {
        return $this->indexPriceCheck('(i.final_price <= 0 OR i.min_price <= 0)', 'i.final_price ASC', 'NULL');
    }

    /**
     * Enabled products whose default-scope special_price is above price while the special
     * date window is active or open-ended.
     *
     * @param \DateTimeImmutable $now
     * @return array{count: int, rows: list<array<string,mixed>>}
     */
    public function specialAboveRegular(\DateTimeImmutable $now): array
    {
        $connection = $this->resourceConnection->getConnection();
        $link = $this->getLinkField();
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $nowSql = $connection->quote($utc->format('Y-m-d H:i:s'));
        $todaySql = $connection->quote($utc->format('Y-m-d') . ' 00:00:00');

        $from = 'FROM ' . $this->table('catalog_product_entity') . ' AS e '
            . $this->join('catalog_product_entity_int', 'st', $link, 'status')
            . $this->join('catalog_product_entity_decimal', 'pr', $link, 'price')
            . $this->join('catalog_product_entity_decimal', 'sp', $link, 'special_price')
            . $this->join('catalog_product_entity_datetime', 'sf', $link, 'special_from_date', true)
            . $this->join('catalog_product_entity_datetime', 'sd', $link, 'special_to_date', true)
            . $this->join('catalog_product_entity_varchar', 'nm', $link, 'name', true)
            . 'WHERE st.value = 1 AND sp.value IS NOT NULL AND pr.value IS NOT NULL AND sp.value > pr.value '
            . "AND (sf.value IS NULL OR sf.value <= {$nowSql}) AND (sd.value IS NULL OR sd.value >= {$todaySql})";

        $count = (int) $connection->fetchOne('SELECT COUNT(*) ' . $from);
        $rows = $connection->fetchAll(
            'SELECT e.sku, nm.value AS name, pr.value AS price, sp.value AS special_price ' . $from
            . ' ORDER BY (sp.value - pr.value) DESC, e.sku ASC LIMIT ' . self::SAMPLE_LIMIT
        );

        return ['count' => $count, 'rows' => $rows];
    }

    /**
     * Top SKUs by quantity ordered since $since (non-canceled orders, top-level items only),
     * with the current default-scope catalog price (null when the product no longer exists).
     * The price is looked up by SKU first: for a configurable the top-level order item carries
     * the parent's product_id (whose own price is usually empty) but the child's SKU. Only when
     * no product has that SKU does it fall back to product_id. Bundles with dynamic pricing
     * have no price of their own and stay null.
     *
     * @param \DateTimeImmutable $since
     * @return list<array{sku: string, name: string, qty: float, orders: int, avg_paid: float, catalog_price: float|null}>
     */
    public function topSellers(\DateTimeImmutable $since): array
    {
        $connection = $this->resourceConnection->getConnection();
        $sinceSql = $connection->quote($since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        $rows = $connection->fetchAll(
            'SELECT oi.sku AS sku, MAX(oi.name) AS name, MAX(oi.product_id) AS product_id, '
            . 'SUM(oi.qty_ordered) AS qty, COUNT(DISTINCT oi.order_id) AS orders, '
            . 'SUM(oi.base_row_total - oi.base_discount_amount) AS paid_total '
            . 'FROM ' . $this->table('sales_order_item') . ' AS oi '
            . 'JOIN ' . $this->table('sales_order') . ' AS o ON o.entity_id = oi.order_id '
            . 'WHERE oi.parent_item_id IS NULL AND o.state <> ' . $connection->quote('canceled')
            . " AND o.created_at >= {$sinceSql} "
            . 'GROUP BY oi.sku HAVING SUM(oi.qty_ordered) > 0 '
            . 'ORDER BY qty DESC, oi.sku ASC LIMIT ' . self::SAMPLE_LIMIT
        );

        $skuPrices = $this->catalogPricesBySku(array_map(static fn (array $r): string => (string) $r['sku'], $rows));
        $idPrices = $this->catalogPricesByProductId(array_values(array_map(
            static fn (array $r): int => (int) $r['product_id'],
            array_filter($rows, static fn (array $r): bool => !isset($skuPrices[(string) $r['sku']]))
        )));

        $result = [];
        foreach ($rows as $row) {
            $qty = (float) $row['qty'];
            $result[] = [
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'qty' => $qty,
                'orders' => (int) $row['orders'],
                'avg_paid' => $qty > 0 ? (float) $row['paid_total'] / $qty : 0.0,
                'catalog_price' => $skuPrices[(string) $row['sku']] ?? $idPrices[(int) $row['product_id']] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Shared body of the two index-price checks: exact distinct-product count plus up to
     * SAMPLE_LIMIT samples, one (worst) row per product.
     *
     * @param string $condition
     * @param string $orderBy
     * @param string $discountExpr
     * @return array{count: int, rows: list<array<string,mixed>>}
     */
    private function indexPriceCheck(string $condition, string $orderBy, string $discountExpr): array
    {
        $connection = $this->resourceConnection->getConnection();
        $link = $this->getLinkField();
        $types = implode(',', array_map([$connection, 'quote'], self::SIMPLE_TYPES));

        $from = 'FROM ' . $this->table('catalog_product_index_price') . ' AS i '
            . 'JOIN ' . $this->table('catalog_product_entity') . ' AS e ON e.entity_id = i.entity_id '
            . $this->join('catalog_product_entity_int', 'st', $link, 'status')
            . $this->join('catalog_product_entity_varchar', 'nm', $link, 'name', true)
            . 'LEFT JOIN ' . $this->table('store_website') . ' AS w ON w.website_id = i.website_id '
            . "WHERE st.value = 1 AND e.type_id IN ({$types}) AND ({$condition})";

        $count = (int) $connection->fetchOne('SELECT COUNT(DISTINCT i.entity_id) ' . $from);

        $fetched = $connection->fetchAll(
            'SELECT i.entity_id, e.sku, nm.value AS name, w.code AS website, i.customer_group_id, '
            . "i.price, i.final_price, {$discountExpr} AS discount_percent {$from} "
            . 'ORDER BY ' . $orderBy . ', i.entity_id ASC LIMIT ' . self::DEDUPE_FETCH_LIMIT
        );

        $rows = [];
        foreach ($fetched as $row) {
            $id = (int) $row['entity_id'];
            if (!isset($rows[$id])) {
                $rows[$id] = $row;
            }
            if (count($rows) >= self::SAMPLE_LIMIT) {
                break;
            }
        }

        return ['count' => $count, 'rows' => array_values($rows)];
    }

    /**
     * Current default-scope price per SKU; SKUs with no product or no price value are absent.
     *
     * @param list<string> $skus
     * @return array<string,float>
     */
    private function catalogPricesBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus, static fn (string $sku): bool => $sku !== '')));
        if ($skus === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $rows = $this->priceRows('e.sku IN (' . implode(',', array_map([$connection, 'quote'], $skus)) . ')');

        $prices = [];
        foreach ($rows as $row) {
            $prices[(string) $row['sku']] = (float) $row['price'];
        }

        return $prices;
    }

    /**
     * Current default-scope price per product entity id; ids with no price value are absent.
     *
     * @param list<int> $productIds
     * @return array<int,float>
     */
    private function catalogPricesByProductId(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            return [];
        }

        $rows = $this->priceRows('e.entity_id IN (' . implode(',', array_map('intval', $productIds)) . ')');

        $prices = [];
        foreach ($rows as $row) {
            $prices[(int) $row['entity_id']] = (float) $row['price'];
        }

        return $prices;
    }

    /**
     * Default-scope price rows (entity_id, sku, price) for products matching the condition.
     *
     * @param string $condition
     * @return list<array<string,mixed>>
     */
    private function priceRows(string $condition): array
    {
        return $this->resourceConnection->getConnection()->fetchAll(
            'SELECT e.entity_id, e.sku, pr.value AS price FROM ' . $this->table('catalog_product_entity') . ' AS e '
            . $this->join('catalog_product_entity_decimal', 'pr', $this->getLinkField(), 'price')
            . 'WHERE ' . $condition
        );
    }

    /**
     * JOIN fragment for one default-scope EAV value row of catalog_product_entity (alias "e").
     *
     * @param string $valueTable
     * @param string $alias
     * @param string $link
     * @param string $attributeCode
     * @param bool $left
     */
    private function join(string $valueTable, string $alias, string $link, string $attributeCode, bool $left = false): string
    {
        return ($left ? 'LEFT JOIN ' : 'JOIN ') . $this->table($valueTable) . " AS {$alias} ON "
            . "{$alias}.{$link} = e.{$link} AND {$alias}.store_id = 0 AND {$alias}.attribute_id = "
            . $this->attributeId($attributeCode) . ' ';
    }

    /**
     * Looks up a catalog_product attribute id by code; cached per instance.
     *
     * @param string $code
     */
    private function attributeId(string $code): int
    {
        if (!isset($this->attributeIds[$code])) {
            $connection = $this->resourceConnection->getConnection();
            $this->attributeIds[$code] = (int) $connection->fetchOne(
                'SELECT a.attribute_id FROM ' . $this->table('eav_attribute') . ' AS a '
                . 'JOIN ' . $this->table('eav_entity_type') . ' AS t ON t.entity_type_id = a.entity_type_id '
                . 'WHERE t.entity_type_code = ' . $connection->quote('catalog_product')
                . ' AND a.attribute_code = ' . $connection->quote($code)
            );
        }

        return $this->attributeIds[$code];
    }

    /**
     * The product link field: entity_id on Open Source, row_id on Adobe Commerce staging.
     */
    private function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = (string) $this->metadataPool
                ->getMetadata(ProductInterface::class)
                ->getLinkField();
        }

        return $this->linkField;
    }

    /**
     * Prefixed table name.
     *
     * @param string $name
     */
    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}
