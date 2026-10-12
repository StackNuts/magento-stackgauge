<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

/**
 * Optional companion to ReporterInterface: which run within its cadence tier collects this
 * reporter. Each tier is split into a few evenly spaced runs (slots) so heavy reporters don't all
 * execute together, but every reporter still runs exactly once per tier period. Slots are always
 * picked by the reporter's author - nothing is assigned automatically. A reporter that doesn't
 * implement this runs in slot 0. A slot at or beyond the configured slot count wraps around
 * (slot modulo count), so lowering the count never drops a reporter.
 */
interface DeclaresSlotInterface
{
    /**
     * Zero-based slot index within the reporter's cadence tier.
     */
    public function getSlot(): int;
}
