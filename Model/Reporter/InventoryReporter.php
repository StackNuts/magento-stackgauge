<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
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

/**
 * "In stock" isn't a product EAV attribute - it lives on cataloginventory_stock_item, one
 * row per product - so this counts directly against that table rather than loading a
 * stock-status collection.
 */
final class InventoryReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_OUT_OF_STOCK = 'inventory.out_of_stock_count';

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'inventory';
    }

    public function getLabel(): string
    {
        return 'Inventory';
    }

    public function getDescription(): string
    {
        return 'Basic inventory counts (in-stock / out-of-stock).';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $total = $this->productCollectionFactory->create()->getSize();

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        $inStock = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])->where('is_in_stock = ?', 1)
        );

        $outOfStock = max(0, $total - $inStock);

        return ['general' => Section::facts('general', 'General', $this->getDescription(), [
            'total_products' => Field::number('Total Products', $total),
            'in_stock' => Field::number('In Stock', $inStock),
            'out_of_stock' => Field::trackableNumber(
                'Out of Stock',
                $outOfStock,
                self::METRIC_OUT_OF_STOCK,
                MetricDefinition::AGGREGATION_LATEST
            ),
        ])];
    }

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
                1500
            ),
        ];
    }
}
