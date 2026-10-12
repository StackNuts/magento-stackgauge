<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use DateTimeImmutable;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

/**
 * Assembles the full-collection report envelope and the smaller config-sync envelope.
 * schema_version covers the envelope shape itself, not any one reporter's data (see
 * Api\ReporterInterface::getSchemaVersion() for that).
 */
class PayloadBuilder
{
    private const SCHEMA_VERSION = '1.0';
    private const PACKAGE_NAME = 'stacknuts/magento-stackgauge';

    /**
     * @param ReporterPool $reporterPool
     * @param MetricCatalogPool $metricCatalogPool
     * @param Config $config
     * @param ComposerLockReader $composerLockReader
     */
    public function __construct(
        private readonly ReporterPool $reporterPool,
        private readonly MetricCatalogPool $metricCatalogPool,
        private readonly Config $config,
        private readonly ComposerLockReader $composerLockReader
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
        return [
            'type' => 'full',
            'cadence' => $cadence,
            'slot' => $slot?->index,
            'slot_count' => $slot?->count,
            'schema_version' => self::SCHEMA_VERSION,
            'module_version' => $this->getModuleVersion(),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
            'reporters' => $this->reporterPool->collect($cadence, $slot),
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

    /**
     * This module's own real release version, from its composer.lock entry - not
     * module.xml's setup_version, which is a DB-schema-migration marker unrelated to which
     * release is actually installed (this module has no schema of its own, so that value
     * would never change regardless of release). Null if composer.lock is missing/malformed
     * or the package isn't in it (e.g. a path-repo dev install outside normal Composer use).
     */
    private function getModuleVersion(): ?string
    {
        $decoded = $this->composerLockReader->getDecoded();
        $packages = is_array($decoded) && is_array($decoded['packages'] ?? null) ? $decoded['packages'] : [];

        foreach ($packages as $package) {
            if (($package['name'] ?? null) === self::PACKAGE_NAME) {
                return is_string($package['version'] ?? null) ? $package['version'] : null;
            }
        }

        return null;
    }
}
