<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ModuleResource;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\DbSchemaReporter;

class DbSchemaReporterTest extends TestCase
{
    private function reporter(array $modules, array $dbVersions): DbSchemaReporter
    {
        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getAll')->willReturn($modules);

        $moduleResource = $this->createMock(ModuleResource::class);
        $moduleResource->method('getDbVersion')->willReturnCallback(
            fn (string $name) => $dbVersions[$name] ?? false
        );

        return new DbSchemaReporter($moduleList, $moduleResource);
    }

    public function testADriftedModuleReportsNameWithoutDuplicatingIt(): void
    {
        $status = $this->reporter(
            ['Vendor_Drifted' => ['setup_version' => '2.0.0']],
            ['Vendor_Drifted' => '1.0.0']
        )->getStatus();

        $rows = $status['drifted']->getRows();
        $fields = $rows[0]->getValue();

        $this->assertSame('Vendor_Drifted', $fields['name']->getValue());
        $this->assertArrayNotHasKey('module', $fields);
        $this->assertSame('2.0.0', $fields['code_version']->getValue());
        $this->assertSame('1.0.0', $fields['db_version']->getValue());
    }

    public function testDriftedCountReflectsTheNumberOfDriftedModules(): void
    {
        $status = $this->reporter(
            [
                'Vendor_Drifted' => ['setup_version' => '2.0.0'],
                'Vendor_InSync' => ['setup_version' => '1.0.0'],
            ],
            ['Vendor_Drifted' => '1.0.0', 'Vendor_InSync' => '1.0.0']
        )->getStatus();

        $this->assertSame(1, $status['general']->getFields()['drifted_count']->getValue());
        $this->assertSame(1, $status['general']->getFields()['in_sync_count']->getValue());
    }

    public function testDeclaresTheDriftedCountAsATrackableMetric(): void
    {
        $reporter = $this->reporter([], []);

        $metrics = $reporter->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('db_schema.drifted_count', $metrics[0]->getMetricKey());
    }
}
