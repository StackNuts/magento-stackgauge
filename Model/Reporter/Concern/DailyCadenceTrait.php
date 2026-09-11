<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter\Concern;

use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

/**
 * Default DeclaresCadenceInterface::getCadence() for reporters that run on the daily cadence.
 * A reporter needing hourly cadence implements getCadence() itself instead.
 */
trait DailyCadenceTrait
{
    public function getCadence(): string
    {
        return DeclaresCadenceInterface::CADENCE_DAILY;
    }
}
