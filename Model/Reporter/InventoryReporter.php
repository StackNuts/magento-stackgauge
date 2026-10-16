<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\EnabledProductSql;

/**
 * Stock status of the catalogue's sellable products. "In stock" isn't a product EAV attribute -
 * it lives on cataloginventory_stock_item - so this counts directly against that table.
 *
 * Counted: enabled (default-scope status) simple, virtual and downloadable products only -
 * configurable/bundle/grouped parents carry no stock of their own and disabled products can't
 * be bought anyway. Products with stock management off (their own manage_stock = 0, or
 * use_config_manage_stock = 1 with the global `cataloginventory/item_options/manage_stock`
 * default off) are always sellable and excluded from both counts. Only the default stock row
 * (stock_id = 1) is read, i.e. legacy CatalogInventory: stores running MSI with additional
 * sources/stocks are not reflected in these figures (MSI keeps per-source quantities in
 * inventory_source_item and salable state in inventory_stock_N).
 */
class InventoryReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_OUT_OF_STOCK = 'inventory.out_of_stock_count';
    private const XML_PATH_MANAGE_STOCK = 'cataloginventory/item_options/manage_stock';
    private const DEFAULT_STOCK_ID = 1;

    /**
     * @param ResourceConnection $resourceConnection
     * @param EnabledProductSql $enabledProductSql
     * @param ScopeConfigInterface $scopeConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EnabledProductSql $enabledProductSql,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the inventory reporter.
     */
    public function getName(): string
    {
        return 'inventory';
    }

    /**
     * Human-readable label for the inventory reporter block.
     */
    public function getLabel(): string
    {
        return 'Inventory';
    }

    /**
     * One-line summary of what the inventory reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Stock status of enabled, stock-managed simple/virtual/downloadable products (default stock).';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the total product count plus in-stock/out-of-stock counts of enabled,
     * stock-managed simple/virtual/downloadable products (see the class docblock).
     */
    public function getStatus(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $total = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->resourceConnection->getTableName('catalog_product_entity')
        );

        $manageStockDefault = $this->scopeConfig->isSetFlag(self::XML_PATH_MANAGE_STOCK) ? 1 : 0;
        $types = implode(',', array_map([$connection, 'quote'], ['simple', 'virtual', 'downloadable']));

        $row = (array) $connection->fetchRow(
            'SELECT COALESCE(SUM(si.is_in_stock = 1), 0) AS in_stock, COALESCE(SUM(si.is_in_stock = 0), 0) AS out_of_stock '
            . $this->enabledProductSql->from()
            . 'JOIN ' . $this->resourceConnection->getTableName('cataloginventory_stock_item') . ' AS si '
            . 'ON si.product_id = e.entity_id AND si.stock_id = ' . self::DEFAULT_STOCK_ID . ' '
            . "WHERE e.type_id IN ({$types}) "
            . "AND IF(si.use_config_manage_stock = 1, {$manageStockDefault}, si.manage_stock) = 1"
        );

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'total_products' => $this->field->number('Total Products', $total),
            'in_stock' => $this->field->number('In Stock', (int) ($row['in_stock'] ?? 0)),
            'out_of_stock' => $this->field->trackableNumber(
                'Out of Stock',
                (int) ($row['out_of_stock'] ?? 0),
                self::METRIC_OUT_OF_STOCK,
                MetricDefinition::AGGREGATION_LATEST
            ),
        ])];
    }

    /**
     * Alertable metric for the inventory reporter: out-of-stock product count.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // Window comfortably outlives the ~24h gap between daily-cadence samples.
            // Threshold is a rough default - catalog sizes vary hugely.
            new MetricDefinition(
                self::METRIC_OUT_OF_STOCK,
                'Inventory: Out of Stock',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                50,
                1500,
                null,
                description: '{value} products are out of stock, above the limit of {threshold}.',
                impact: 'Customers see unbuyable products, which loses sales.'
            ),
        ];
    }
}
