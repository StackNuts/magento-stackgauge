<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\ReportSender;
use Throwable;

/**
 * Daily safety-net sync of the trackable-metric catalog + suggested defaults (see
 * Model\PayloadBuilder::buildConfigSync()). The two other triggers -
 * Observer\ConfigSyncOnSectionSave and the admin Test Ping button - fire instantly on the
 * events that actually change what's trackable; this job exists so a change is never more
 * than a day stale even if both of those are missed. Never lets an exception escape, same
 * discipline as every other cron job in this module.
 */
class SendConfigSync
{
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->reportSender->sendConfigSync();
        } catch (Throwable $e) {
            $this->logger->critical('StackGauge: SendConfigSync cron job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
