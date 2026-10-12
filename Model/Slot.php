<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\ReporterInterface;

/**
 * One run within a cadence tier: slot $index of $count.
 */
class Slot
{
    /**
     * @param int $index
     * @param int $count
     */
    public function __construct(public readonly int $index, public readonly int $count)
    {
    }

    /**
     * Whether this run should collect the given reporter.
     *
     * @param ReporterInterface $reporter
     */
    public function includes(ReporterInterface $reporter): bool
    {
        $declared = $reporter instanceof DeclaresSlotInterface ? max(0, $reporter->getSlot()) : 0;

        return $declared % max(1, $this->count) === $this->index;
    }
}
