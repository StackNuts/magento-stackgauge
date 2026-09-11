<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use Throwable;

/**
 * Cheaply enumerates every trackable metric across every registered reporter (built-in and
 * third-party) that opts into Api\MetricCatalogInterface, for the config-sync payload - see
 * that interface's docblock for why this must stay side-effect-free, unlike ReporterPool's
 * getStatus() collection. Same per-reporter error isolation as ReporterPool::collectOne().
 */
class MetricCatalogPool
{
    public function __construct(
        private readonly ReporterPool $reporterPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, MetricDefinition>
     */
    public function collect(): array
    {
        $result = [];

        foreach ($this->reporterPool->getReporters() as $reporter) {
            if (!$reporter instanceof MetricCatalogInterface) {
                continue;
            }

            try {
                foreach ($reporter->getTrackableMetrics() as $metric) {
                    if (!$metric instanceof MetricDefinition) {
                        throw new InvalidArgumentException(sprintf(
                            'getTrackableMetrics() must return MetricDefinition instances, got %s',
                            get_debug_type($metric)
                        ));
                    }

                    $result[$metric->getMetricKey()] = $metric;
                }
            } catch (Throwable $e) {
                $this->logger->warning(sprintf(
                    'StackGauge: metric catalog for reporter "%s" failed: %s',
                    $reporter->getName(),
                    $e->getMessage()
                ), ['exception' => $e]);
            }
        }

        return $result;
    }
}
