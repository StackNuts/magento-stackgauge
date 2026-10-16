<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ModuleResource;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

/**
 * Flags modules where the code's declared setup_version (module.xml) has moved ahead of
 * what's actually recorded in the setup_module DB table - the classic "deploy ran but
 * setup:upgrade never did" drift, invisible from the outside and easy to miss per-site
 * without an inventory like this.
 */
class DbSchemaReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_DRIFTED_COUNT = 'db_schema.drifted_count';

    /**
     * @param ModuleListInterface $moduleList
     * @param ModuleResource $moduleResource
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ModuleResource $moduleResource,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the db-schema-drift reporter.
     */
    public function getName(): string
    {
        return 'db_schema';
    }

    /**
     * Human-readable label for the db-schema-drift reporter block.
     */
    public function getLabel(): string
    {
        return 'DB Schema Drift';
    }

    /**
     * One-line summary of what the db-schema-drift reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Modules whose code setup_version has moved ahead of what setup:upgrade has actually applied.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the in-sync and drifted module counts, plus a table of drifted modules.
     *
     * Compares each module's code setup_version (ModuleListInterface) against its setup_module DB row
     * (ModuleResource); a module with a setup_version but no row counts as drifted ("not installed").
     *
     * Limitation: a declarative-schema-only module has no setup_version and no setup_module row, so
     * pending db_schema.xml changes or data patches for it are not detected here - that needs
     * Magento's full declarative diff (setup:db:status), too heavy to run in a report.
     */
    public function getStatus(): array
    {
        $drifted = [];
        $inSyncCount = 0;

        foreach ($this->moduleList->getAll() as $name => $info) {
            $codeVersion = $info['setup_version'] ?? null;
            if (!$codeVersion) {
                continue;
            }

            // No setup_module row at all for a module that declares a setup_version is the
            // classic "deployed without setup:upgrade" case - drift, shown as "not installed".
            $dbVersion = $this->moduleResource->getDbVersion($name);

            if ($dbVersion === false || $dbVersion !== $codeVersion) {
                $drifted[] = $this->field->array($name, [
                    'name' => $this->field->varchar('Name', $name),
                    'code_version' => $this->field->varchar('Code Version', $codeVersion),
                    'db_version' => $this->field->varchar('DB Version', $dbVersion === false ? 'not installed' : $dbVersion),
                ]);
            } else {
                $inSyncCount++;
            }
        }

        return [
            'general' => $this->section->facts('general', 'General', '', [
                'in_sync_count' => $this->field->number('In-Sync Module Count', $inSyncCount),
                'drifted_count' => $this->field->trackableNumber(
                    'Drifted Module Count',
                    count($drifted),
                    self::METRIC_DRIFTED_COUNT,
                    MetricDefinition::AGGREGATION_LATEST
                ),
            ]),
            'drifted' => $this->section->table('drifted', 'Drifted Modules', '', $drifted),
        ];
    }

    /**
     * Alertable metric for the db-schema-drift reporter: any module with a drifted setup_version.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // Window comfortably outlives the ~24h gap between daily-cadence samples. Any
            // drift at all is worth flagging (threshold 0).
            new MetricDefinition(
                self::METRIC_DRIFTED_COUNT,
                'DB Schema: Drifted Module Count',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                1500,
                null,
                description: '{value} modules have a database schema that differs from their definition.',
                impact: 'Upgrades may fail or data may be lost.'
            ),
        ];
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }
}
