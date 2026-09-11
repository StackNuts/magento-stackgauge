<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

/**
 * Optional companion to ReporterInterface, letting a reporter opt into the slower "daily"
 * cadence instead of the default hourly one, for data that doesn't change hour to hour
 * (module inventory, patches, security posture). A reporter that doesn't implement this is
 * always collected at hourly cadence.
 */
interface DeclaresCadenceInterface
{
    public const CADENCE_HOURLY = 'hourly';
    public const CADENCE_DAILY = 'daily';

    public function getCadence(): string;
}
