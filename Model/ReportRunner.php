<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use InvalidArgumentException;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

/**
 * The one place that knows what runs exist (every slot of both tiers, plus the config sync),
 * how a target like "daily:1" maps to them, and how to build or send one. Used by the console
 * command and the admin "Send All Data Now" button, so they can't drift from the cron jobs.
 */
class ReportRunner
{
    public const TARGET_ALL = 'all';
    public const TARGET_CURRENT = 'current';

    /**
     * @param ReportSender $reportSender
     * @param SlotResolver $slotResolver
     * @param Config $config
     */
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly SlotResolver $slotResolver,
        private readonly Config $config
    ) {
    }

    /**
     * Every full-report slot of both tiers, then the config sync.
     *
     * @return ReportRun[]
     */
    public function all(): array
    {
        return [
            ...$this->tier(DeclaresCadenceInterface::CADENCE_HOURLY),
            ...$this->tier(DeclaresCadenceInterface::CADENCE_DAILY),
            new ReportRun(null),
        ];
    }

    /**
     * Turns a target into runs: "all", "hourly", "daily", "hourly:N", "daily:N", "current"
     * (the hourly slot the clock is in now) or "config".
     *
     * @param string $target
     * @return ReportRun[]
     * @throws InvalidArgumentException
     */
    public function resolve(string $target): array
    {
        $target = strtolower(trim($target));

        if ($target === self::TARGET_ALL) {
            return $this->all();
        }
        if ($target === 'config') {
            return [new ReportRun(null)];
        }
        if ($target === self::TARGET_CURRENT) {
            return [new ReportRun(DeclaresCadenceInterface::CADENCE_HOURLY, $this->slotResolver->currentHourly())];
        }

        [$cadence, $index] = array_pad(explode(':', $target, 2), 2, null);
        if (!in_array($cadence, [DeclaresCadenceInterface::CADENCE_HOURLY, DeclaresCadenceInterface::CADENCE_DAILY], true)
            || ($index !== null && !ctype_digit($index))
        ) {
            throw new InvalidArgumentException(sprintf(
                'Unknown target "%s". Use all, hourly, daily, hourly:N, daily:N, current or config.',
                $target
            ));
        }

        if ($index === null) {
            return $this->tier($cadence);
        }

        $count = $this->config->getSlotCount($cadence);
        if ((int)$index >= $count) {
            throw new InvalidArgumentException(sprintf('%s has %d slot(s), numbered 0 to %d.', $cadence, $count, $count - 1));
        }

        return [new ReportRun($cadence, $this->slotResolver->forIndex($cadence, (int)$index))];
    }

    /**
     * The payload a run would send, without sending it.
     *
     * @param ReportRun $run
     * @return array<string, mixed>
     */
    public function build(ReportRun $run): array
    {
        return $run->isConfigSync()
            ? $this->reportSender->buildConfigSync()
            : $this->reportSender->buildPayload((string)$run->cadence, $run->slot);
    }

    /**
     * Sends a run; $force ignores the admin "Enabled" toggle.
     *
     * @param ReportRun $run
     * @param bool $force
     */
    public function send(ReportRun $run, bool $force): bool
    {
        if ($run->isConfigSync()) {
            return $force ? $this->reportSender->sendConfigSyncNow() : $this->reportSender->sendConfigSync();
        }

        return $force
            ? $this->reportSender->sendNow((string)$run->cadence, $run->slot)
            : $this->reportSender->send((string)$run->cadence, $run->slot);
    }

    /**
     * @param string $cadence
     * @return ReportRun[]
     */
    private function tier(string $cadence): array
    {
        $runs = [];
        for ($index = 0, $count = $this->config->getSlotCount($cadence); $index < $count; $index++) {
            $runs[] = new ReportRun($cadence, $this->slotResolver->forIndex($cadence, $index));
        }

        return $runs;
    }
}
