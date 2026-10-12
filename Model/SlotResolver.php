<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

/**
 * Resolves hourly slots from the clock (so a late or skipped cron tick never shifts which
 * reporters the next one collects) and builds explicit slots for the daily tier.
 */
class SlotResolver
{
    /**
     * @param Config $config
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly Config $config,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * The hourly slot the current time falls in. Daily slots are fixed per cron job instead (a
     * day's runs needn't be evenly spaced), so they're built with forIndex().
     */
    public function currentHourly(): Slot
    {
        $count = $this->config->getSlotCount(DeclaresCadenceInterface::CADENCE_HOURLY);
        $minute = (int)$this->timezone->date()->format('i');

        return new Slot(intdiv($minute * $count, 60), $count);
    }

    /**
     * A specific slot of the tier, with the configured slot count.
     *
     * @param string $cadence
     * @param int $index
     */
    public function forIndex(string $cadence, int $index): Slot
    {
        $count = $this->config->getSlotCount($cadence);

        return new Slot(max(0, $index) % $count, $count);
    }
}
