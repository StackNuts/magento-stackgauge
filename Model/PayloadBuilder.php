<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use DateTimeImmutable;
use Magento\Framework\Module\ModuleListInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\MetricDefinition;

/**
 * Assembles the full-collection report envelope and the smaller config-sync envelope.
 * schema_version covers the envelope shape itself, not any one reporter's data (see
 * Api\ReporterInterface::getSchemaVersion() for that).
 */
class PayloadBuilder
{
    private const SCHEMA_VERSION = '1.0';
    private const MODULE_NAME = 'StackNuts_StackGauge';

    public function __construct(
        private readonly ReporterPool $reporterPool,
        private readonly MetricCatalogPool $metricCatalogPool,
        private readonly Config $config,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): array
    {
        return [
            'type' => 'full',
            'cadence' => $cadence,
            'schema_version' => self::SCHEMA_VERSION,
            'module_version' => $this->getModuleVersion(),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
            'reporters' => $this->reporterPool->collect($cadence),
        ];
    }

    /**
     * The "what's trackable, and what's a sensible starting alert rule" envelope -
     * deliberately does NOT carry any editable alert-rule state, only the catalog and
     * suggested defaults. See Api\MetricCatalogInterface and MetricDefinition.
     *
     * @return array<string, mixed>
     */
    public function buildConfigSync(): array
    {
        return [
            'type' => 'config',
            'schema_version' => self::SCHEMA_VERSION,
            'module_version' => $this->getModuleVersion(),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
            'metrics' => array_values(array_map(
                static fn (MetricDefinition $metric): array => $metric->jsonSerialize(),
                $this->metricCatalogPool->collect()
            )),
        ];
    }

    private function getModuleVersion(): ?string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);

        return $module['setup_version'] ?? null;
    }
}
