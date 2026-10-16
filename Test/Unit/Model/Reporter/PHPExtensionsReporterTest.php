<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\PHPExtensionsReporter;

class PHPExtensionsReporterTest extends TestCase
{
    public function testGetStatusReturnsExtensions(): void
    {
        $reporter = new PHPExtensionsReporter(new Field(), new Section());
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('php_extensions', $status);
        $this->assertArrayHasKey('php_settings', $status);
    }

    public function testEnabledIsCriticalWhenFalse(): void
    {
        $reporter = new PHPExtensionsReporter(new Field(), new Section());
        $row = $reporter->getStatus()['php_extensions']->getRows()[0]->getValue();

        $this->assertFalse($row['enabled']->jsonSerialize()['critical_when']);
    }

    public function testIsLabelledCliAndChecksMagentoRequiredExtensions(): void
    {
        $reporter = new PHPExtensionsReporter(new Field(), new Section());
        $names = array_map(
            static fn ($row): string => $row->getValue()['name']->getValue(),
            $reporter->getStatus()['php_extensions']->getRows()
        );

        $this->assertStringContainsString('CLI', $reporter->getLabel());
        $this->assertNotContains('json', $names);
        $this->assertContains('sodium', $names);
        $this->assertContains('pdo_mysql', $names);
    }

    public function testCliOpcacheStateIsNotColouredAsAProblem(): void
    {
        $settings = (new PHPExtensionsReporter(new Field(), new Section()))->getStatus()['php_settings']->getFields();

        $this->assertNull($settings['opcache_enabled']->jsonSerialize()['critical_when'] ?? null);
    }
}
