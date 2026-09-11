<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;

final class PaymentMethodsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly PaymentConfig $paymentConfig,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'payments';
    }

    public function getLabel(): string
    {
        return 'Payment Methods';
    }

    public function getDescription(): string
    {
        return 'Enabled payment methods and key configuration flags.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getCadence(): string
    {
        return self::CADENCE_HOURLY;
    }

    public function getStatus(): array
    {
        $methods = [];
        $active = $this->paymentConfig->getActiveMethods();
        foreach ($active as $code => $method) {
            $title = '';
            if (is_object($method) && method_exists($method, 'getTitle')) {
                $title = (string)$method->getTitle();
            } else {
                $title = (string)$this->scopeConfig->getValue('payment/' . $code . '/title');
            }
            $activeFlag = (bool)$this->scopeConfig->getValue('payment/' . $code . '/active');
            $methods[] = Field::array('', [
                'code' => Field::varchar('Code', (string)$code),
                'title' => Field::varchar('Title', $title),
                'active' => Field::bool('Active', $activeFlag),
            ]);
        }

        return ['payments' => Section::table('payments', 'Active Methods', $this->getDescription(), $methods, keyName: 'code')];
    }
}
