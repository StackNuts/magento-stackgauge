<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\PHPExtensionsReporter;

class PHPExtensionsReporterTest extends TestCase
{
    public function testGetStatusReturnsExtensions(): void
    {
        $reporter = new PHPExtensionsReporter();
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('php_extensions', $status);
        $this->assertArrayHasKey('php_settings', $status);
    }

    public function testEnabledIsCriticalWhenFalse(): void
    {
        $reporter = new PHPExtensionsReporter();
        $row = $reporter->getStatus()['php_extensions']->getRows()[0]->getValue();

        $this->assertFalse($row['enabled']->jsonSerialize()['critical_when']);
    }
}
