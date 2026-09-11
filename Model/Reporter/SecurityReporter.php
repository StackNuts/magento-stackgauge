<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Module\ModuleListInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

class SecurityReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const DEFAULT_ADMIN_PATH = 'admin';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    public function getName(): string
    {
        return 'security';
    }

    public function getLabel(): string
    {
        return 'Security';
    }

    public function getDescription(): string
    {
        return 'Whether the admin path is still the default, maintenance-mode flag, sample-data modules present.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }



    public function getStatus(): array
    {
        $sampleDataModules = array_values(array_filter(
            array_keys($this->moduleList->getAll()),
            static fn (string $name): bool => str_contains($name, 'SampleData')
        ));

        return [
            'general' => Section::facts('general', 'General', '', [
                // Deliberately a boolean, not the actual admin path string - sending every
                // client's real (deliberately obscured) admin URL to a third-party dashboard
                // would concentrate exactly the secret that obscurity is meant to protect.
                'is_default_admin_path' => Field::bool(
                    'Is Default Admin Path',
                    $this->getAdminFrontName() === self::DEFAULT_ADMIN_PATH,
                    criticalWhen: true
                ),
                'maintenance_mode' => Field::bool('Maintenance Mode', $this->maintenanceMode->isOn(), criticalWhen: true),
                'sample_data_present' => Field::bool('Sample Data Present', $sampleDataModules !== [], criticalWhen: true),
            ]),
            'sample_data_modules' => Section::table('sample_data_modules', 'Sample Data Modules', '', array_map(
                static fn (string $name) => Field::array($name, [
                    'module' => Field::varchar('Module', $name),
                ]),
                $sampleDataModules
            ), keyName: 'module'),
        ];
    }

    private function getAdminFrontName(): string
    {
        return (string)($this->deploymentConfig->get('backend/frontName') ?? self::DEFAULT_ADMIN_PATH);
    }
}
