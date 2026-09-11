<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use StackNuts\StackGauge\Model\Reporter\PaymentMethodsReporter;

class PaymentMethodsReporterTest extends TestCase
{
    public function testGetStatusReturnsMethods(): void
    {
        $method = new class {
            public function getTitle() { return 'Test Method'; }
        };

        $paymentConfig = $this->createMock(PaymentConfig::class);
        $paymentConfig->method('getActiveMethods')->willReturn(['test' => $method]);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['payment/test/title', null, null, 'Test Method'],
            ['payment/test/active', null, null, '1'],
        ]);

        $reporter = new PaymentMethodsReporter($paymentConfig, $scopeConfig);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('payments', $status);

        $rows = $status['payments']->getRows();
        $fields = $rows[0]->getValue();
        $this->assertArrayNotHasKey('name', $fields);
        $this->assertSame('Test Method', $fields['title']->getValue());
    }
}
