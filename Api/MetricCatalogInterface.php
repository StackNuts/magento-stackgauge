<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

/**
 * Optional companion to ReporterInterface. Implement this ONLY if a reporter has one or more
 * numeric fields worth alerting on. Unlike getStatus(), this must be cheap and side-effect-free
 * (no Redis/Elasticsearch/network calls) - it's read to build the config-sync payload
 * (Model\PayloadBuilder::buildConfigSync()), not on every full-report collection run. A
 * reporter that has nothing worth alerting on simply doesn't implement this interface -
 * Model\MetricCatalogPool skips anything that doesn't.
 */
interface MetricCatalogInterface
{
    /**
     * @return MetricDefinition[]
     */
    public function getTrackableMetrics(): array;
}
