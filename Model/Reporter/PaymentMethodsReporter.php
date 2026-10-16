<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;

class PaymentMethodsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use CommerceSectionTrait;

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
     * Payload key for the payment-methods reporter.
     */
    public function getName(): string
    {
        return 'payments';
    }

    /**
     * Human-readable label for the payment-methods reporter block.
     */
    public function getLabel(): string
    {
        return 'Payment Methods';
    }

    /**
     * One-line summary of what the payment-methods reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Payment methods enabled in at least one store view, and which store views.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Overrides DeclaresCadenceInterface's default to keep payment method status on the hourly cadence.
     */
    public function getCadence(): string
    {
        return self::CADENCE_HOURLY;
    }

    /**
     * Reports each payment method switched on in at least one store view, with the store views
     * it is active in. Reading each store's own flag (rather than the default scope, as
     * Payment\Model\Config::getActiveMethods() does) catches a method enabled for one store view
     * only; "active" is therefore true for every row listed, "stores" says where.
     */
    public function getStatus(): array
    {
        $configured = $this->scopeConfig->getValue('payment');
        $stores = $this->storeManager->getStores(false);

        $methods = [];
        foreach (is_array($configured) ? $configured : [] as $code => $data) {
            if (!is_array($data) || !isset($data['model'])) {
                continue;
            }

            $activeIn = [];
            foreach ($stores as $store) {
                $flag = $this->scopeConfig->getValue(
                    'payment/' . $code . '/active',
                    ScopeInterface::SCOPE_STORES,
                    $store->getId()
                );
                if ($flag) {
                    $activeIn[] = (string)$store->getCode();
                }
            }
            if ($activeIn === []) {
                continue;
            }

            $title = (string)$this->scopeConfig->getValue(
                'payment/' . $code . '/title',
                ScopeInterface::SCOPE_STORES,
                $stores[array_key_first($stores)]->getId()
            );
            $methods[] = $this->field->array((string)$code, [
                'code' => $this->field->varchar('Code', (string)$code),
                'title' => $this->field->varchar('Title', $title),
                'active' => $this->field->bool('Active', true),
                'stores' => $this->field->varchar('Active In Stores', implode(', ', $activeIn)),
            ]);
        }

        return [
            'payments' => $this->section->table(
                'payments',
                'Active Methods',
                $this->getDescription(),
                $methods,
                keyName: 'code'
            ),
        ];
    }
}
