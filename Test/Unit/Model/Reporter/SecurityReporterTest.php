<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Module\ModuleListInterface;
use StackNuts\StackGauge\Model\Reporter\SecurityReporter;

class SecurityReporterTest extends TestCase
{
    public function testGetStatusReturnsSecurityInfo(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $maintenance = $this->createMock(MaintenanceMode::class);
        $maintenance->method('isOn')->willReturn(false);
        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getAll')->willReturn([]);

        $reporter = new SecurityReporter($deploymentConfig, $maintenance, $moduleList);
        $fields = $reporter->getStatus()['general']->getFields();

        $this->assertArrayHasKey('is_default_admin_path', $fields);
        $this->assertArrayHasKey('maintenance_mode', $fields);
    }
}
