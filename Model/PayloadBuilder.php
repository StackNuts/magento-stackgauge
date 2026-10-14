<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

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

    /**
     * @param ReporterPool $reporterPool
     * @param MetricCatalogPool $metricCatalogPool
     * @param PayloadEnvelope $envelope
     */
    public function __construct(
        private readonly ReporterPool $reporterPool,
        private readonly MetricCatalogPool $metricCatalogPool,
        private readonly PayloadEnvelope $envelope
    ) {
    }

    /**
     * The full-collection report envelope for the given cadence tier.
     *
     * @param string $cadence One of Api\DeclaresCadenceInterface::CADENCE_*.
     * @param Slot|null $slot The run within the tier; null means the whole tier.
     * @return array<string, mixed>
     */
    public function build(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY, ?Slot $slot = null): array
    {
        return $this->envelope->create('full', self::SCHEMA_VERSION) + [
            'cadence' => $cadence,
            'slot' => $slot?->index,
            'slot_count' => $slot?->count,
            'reporters' => $this->reporterPool->collect($cadence, $slot),
        ];
    }

    /**
     * The config-sync envelope: every trackable metric this site declares, for the dashboard
     * to auto-create/update its AlertRules from.
     *
     * @return array<string, mixed>
     */
    public function buildConfigSync(): array
    {
        return $this->envelope->create('config', self::SCHEMA_VERSION) + [
            'metrics' => array_values(array_map(
                static fn (MetricDefinition $metric): array => $metric->jsonSerialize(),
                $this->metricCatalogPool->collect()
            )),
        ];
    }
}
