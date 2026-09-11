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
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->reportSender->send(DeclaresCadenceInterface::CADENCE_DAILY);
        } catch (Throwable $e) {
            $this->logger->critical('StackGauge: SendDailyReport cron job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
