<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use DateTimeImmutable;
use DateTimeZone;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Model\Util\Clock;
use Throwable;

/**
 * Resolves hourly slots from the time a cron job was scheduled for, always in UTC (so a late or
 * skipped tick never shifts which reporters the next one collects, and the store timezone's
 * offset can't skew the minute), and builds explicit slots for the daily tier.
 */
class SlotResolver
{
    /**
     * @param Config $config
     * @param Clock $clock
     */
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock
    ) {
    }

    /**
     * The hourly slot a job scheduled for $scheduledAt belongs to. Falls back to the current UTC
     * time when it is null or unparseable (console and admin runs have no schedule). Daily slots
     * are fixed per cron job instead (a day's runs needn't be evenly spaced), so they're built
     * with forIndex().
     *
     * @param string|null $scheduledAt The cron_schedule.scheduled_at value, which Magento stores in UTC.
     */
    public function currentHourly(?string $scheduledAt = null): Slot
    {
        $count = $this->config->getSlotCount(DeclaresCadenceInterface::CADENCE_HOURLY);
        $minute = (int)$this->moment($scheduledAt)->format('i');

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

    /**
     * $scheduledAt as a UTC instant, or now when absent or malformed.
     *
     * @param string|null $scheduledAt
     */
    private function moment(?string $scheduledAt): DateTimeImmutable
    {
        if ($scheduledAt !== null && $scheduledAt !== '') {
            try {
                return new DateTimeImmutable($scheduledAt, new DateTimeZone('UTC'));
            } catch (Throwable) {
                // fall through to now
            }
        }

        return $this->clock->now();
    }
}
