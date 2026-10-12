<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Model\SlotResolver;
use Throwable;

/**
 * Hourly full-collection job (see etc/crontab.xml, "stackgauge" cron group). Wraps the whole
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
     * Runs the hourly full report job - see this class's own docblock for why it never throws.
     */
    public function execute(): void
    {
        try {
            $this->reportSender->send(
                DeclaresCadenceInterface::CADENCE_HOURLY,
                $this->slotResolver->currentHourly()
            );
        } catch (Throwable $e) {
            $this->logger->critical(
                'StackGauge: SendReport cron job failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
