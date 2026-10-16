<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Util;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Builds the FROM/JOIN fragment for "enabled products" as plain SQL on the EAV tables, so a
 * reporter counts against catalog_product_entity directly instead of a product collection
 * (which can be redirected to a flat table in some areas and would then miss new products).
 *
 * Reads the default-scope (store_id = 0) status value only: a product enabled globally but
 * disabled on one store view still counts as enabled, and one disabled globally but enabled on
 * a view does not. The EAV value table is joined on the product's link field (entity_id on
 * Open Source, row_id on Adobe Commerce staging), resolved through the MetadataPool.
 */
class EnabledProductSql
{
    private const STATUS_ENABLED = 1;

    private ?int $statusAttributeId = null;

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
     * "FROM catalog_product_entity AS e JOIN ... status = enabled" - append further joins and a WHERE.
     */
    public function from(): string
    {
        $link = $this->getLinkField();

        return 'FROM ' . $this->resourceConnection->getTableName('catalog_product_entity') . ' AS e '
            . 'JOIN ' . $this->resourceConnection->getTableName('catalog_product_entity_int') . ' AS st '
            . "ON st.{$link} = e.{$link} AND st.store_id = 0 AND st.attribute_id = "
            . $this->getStatusAttributeId() . ' AND st.value = ' . self::STATUS_ENABLED . ' ';
    }

    /**
     * Product status attribute id, cached per instance.
     */
    private function getStatusAttributeId(): int
    {
        if ($this->statusAttributeId === null) {
            $connection = $this->resourceConnection->getConnection();
            $this->statusAttributeId = (int) $connection->fetchOne(
                'SELECT a.attribute_id FROM ' . $this->resourceConnection->getTableName('eav_attribute') . ' AS a '
                . 'JOIN ' . $this->resourceConnection->getTableName('eav_entity_type') . ' AS t '
                . 'ON t.entity_type_id = a.entity_type_id WHERE t.entity_type_code = '
                . $connection->quote('catalog_product') . ' AND a.attribute_code = ' . $connection->quote('status')
            );
        }

        return $this->statusAttributeId;
    }

    /**
     * The product link field: entity_id on Open Source, row_id on Adobe Commerce staging.
     */
    private function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = (string) $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        }

        return $this->linkField;
    }
}
