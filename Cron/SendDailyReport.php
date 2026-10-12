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
 * Daily full-collection job for the slower-cadence reporters (module inventory, patches,
 * security posture, composer/db-schema drift - anything a reporter opts into via
 * Api\DeclaresCadenceInterface returning "daily"). See etc/crontab.xml, "stackgauge" cron
 * group. Wraps the whole body in try/catch for the same reason as Cron\SendReport - a cron
 * job in this module must never throw.
 */
class SendDailyReport
{
    /**
     * @param ReportSender $reportSender
     * @param SlotResolver $slotResolver
     * @param LoggerInterface $logger
     * @param int $slot Which daily slot this job collects - fixed per cron job, not read from the clock.
     */
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly SlotResolver $slotResolver,
        private readonly LoggerInterface $logger,
        protected readonly int $slot = 0
    ) {
    }

    /**
     * Runs the daily-cadence full report job - see this class's own docblock for scope.
     */
    public function execute(): void
    {
        try {
            $cadence = DeclaresCadenceInterface::CADENCE_DAILY;
            $this->reportSender->send($cadence, $this->slotResolver->forIndex($cadence, $this->slot));
        } catch (Throwable $e) {
            $this->logger->critical(
                'StackGauge: SendDailyReport cron job failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
