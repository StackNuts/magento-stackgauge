<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

/**
 * One unit of work the agent sends: a full report for one slot of a cadence tier, or the
 * config sync.
 */
class ReportRun
{
    /**
     * @param string|null $cadence One of Api\DeclaresCadenceInterface::CADENCE_*; null for the config sync.
     * @param Slot|null $slot
     */
    public function __construct(public readonly ?string $cadence, public readonly ?Slot $slot = null)
    {
    }

    /**
     * Whether this run is the config sync rather than a full report.
     */
    public function isConfigSync(): bool
    {
        return $this->cadence === null;
    }

    /**
     * Same notation the console command accepts, e.g. "hourly:1" or "config".
     */
    public function label(): string
    {
        return $this->cadence === null ? 'config' : $this->cadence . ':' . ($this->slot?->index ?? 'all');
    }
}
