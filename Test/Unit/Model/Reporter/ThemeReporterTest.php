<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use StackNuts\StackGauge\Model\Reporter\ThemeReporter;

class ThemeReporterTest extends TestCase
{
    public function testGetStatusReturnsThemes(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnOnConsecutiveCalls('frontend_theme', 'admin_theme');

        $reporter = new ThemeReporter($scopeConfig);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('themes', $status);
    }
}
