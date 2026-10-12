<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Model\SlotResolver;

/**
 * Second daily run - collects the reporters declaring daily slot 1. See Cron\SendDailyReport.
 */
class SendDailyReportSlot1 extends SendDailyReport
{
    /**
     * @param ReportSender $reportSender
     * @param SlotResolver $slotResolver
     * @param LoggerInterface $logger
     */
    public function __construct(ReportSender $reportSender, SlotResolver $slotResolver, LoggerInterface $logger)
    {
        parent::__construct($reportSender, $slotResolver, $logger, 1);
    }
}
