<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Cron;

use Magento\Cron\Model\Schedule;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Model\SlotResolver;
use Throwable;

/**
 * Full-collection job for the hourly tier, run every 20 minutes (see etc/config.xml, "stackgauge"
 * cron group): each run collects one of the tier's three slots, so every hourly reporter reports
 * once an hour. Wraps the whole
 * body in try/catch - a cron job in this module must never throw, since Magento's cron
 * runner treats an uncaught exception here as a failed job, and a broken dashboard/network
 * shouldn't affect any other scheduled task on the site.
 */
class SendReport
{
    /**
     * @param ReportSender $reportSender
     * @param SlotResolver $slotResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly SlotResolver $slotResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Runs the hourly-tier job - see this class's own docblock for why it never throws.
     *
     * @param Schedule|null $schedule The cron row; its scheduled_at (UTC) picks the slot.
     */
    public function execute(?Schedule $schedule = null): void
    {
        try {
            $this->reportSender->send(
                DeclaresCadenceInterface::CADENCE_HOURLY,
                $this->slotResolver->currentHourly($schedule?->getScheduledAt())
            );
        } catch (Throwable $e) {
            $this->logger->critical(
                'StackGauge: SendReport cron job failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
