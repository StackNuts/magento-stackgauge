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
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\ReportSender;
use Throwable;

/**
 * Fires an immediate config-sync whenever this module's own system.xml section is saved (e.g.
 * toggling which reporters are enabled), rather than waiting for the daily cron to pick it
 * up. Bound to the admin_system_config_changed_section_stacknuts_stackgauge event (see
 * etc/adminhtml/events.xml). Does nothing while StackGauge is disabled, so turning it off doesn't make the save wait on the
 * network; the failure is logged, never thrown, so it can't block the save.
 */
class ConfigSyncOnSectionSave implements ObserverInterface
{
    /**
     * @param ReportSender $reportSender
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Fires an immediate config-sync - see this class's own docblock for when this runs.
     *
     * @param EventObserver $observer
     */
    public function execute(EventObserver $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $this->reportSender->sendConfigSync();
        } catch (Throwable $e) {
            $this->logger->warning(
                'StackGauge: config sync on section save failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
