<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\ReportSender;
use Throwable;

/**
 * Fires an immediate config-sync whenever this module's own system.xml section is saved (e.g.
 * toggling which reporters are enabled), rather than waiting for the daily cron to pick it
 * up. Bound to the admin_system_config_changed_section_stacknuts_stackgauge event (see
 * etc/adminhtml/events.xml). Uses sendConfigSyncNow(), bypassing the "Enabled" toggle.
 */
class ConfigSyncOnSectionSave implements ObserverInterface
{
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        try {
            $this->reportSender->sendConfigSyncNow();
        } catch (Throwable $e) {
            $this->logger->warning(
                'StackGauge: config sync on section save failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
