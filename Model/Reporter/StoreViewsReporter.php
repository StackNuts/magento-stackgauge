<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

class StoreViewsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the store-views reporter.
     */
    public function getName(): string
    {
        return 'store_views';
    }

    /**
     * Human-readable label for the store-views reporter block.
     */
    public function getLabel(): string
    {
        return 'Store Views';
    }

    /**
     * One-line summary of what the store-views reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Configured store views and basic locale/currency information.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports each configured store view's code, name, base URL, locale, and default currency.
     */
    public function getStatus(): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $id = (int)$store->getId();
            $baseUrl = method_exists($store, 'getBaseUrl') ? (string)$store->getBaseUrl() : '';
            $currency = (string)$this->scopeConfig->getValue('currency/options/default', 'stores', $id);
            $locale = (string)$this->scopeConfig->getValue('general/locale/code', 'stores', $id);

            $stores[] = $this->field->array((string)$store->getCode(), [
                'id' => $this->field->number('ID', $id),
                'code' => $this->field->varchar('Code', (string)$store->getCode()),
                'name' => $this->field->varchar('Name', (string)$store->getName()),
                'base_url' => $this->field->varchar('Base URL', $baseUrl),
                'locale' => $this->field->varchar('Locale', $locale),
                'currency' => $this->field->varchar('Currency', $currency),
            ]);
        }

        return [
            'store_views' => $this->section->table(
                'store_views',
                'Configured Stores',
                $this->getDescription(),
                $stores,
                keyName: 'code'
            ),
        ];
    }
}
