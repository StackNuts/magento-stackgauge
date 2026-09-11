<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Plugin\App;

use Magento\Framework\App\MaintenanceMode;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\HeartbeatSender;
use Throwable;

/**
 * Fires an out-of-band heartbeat right after `bin/magento maintenance:enable|disable`, so a
 * site stuck in maintenance mode (or one that just came back from it) is visible without
 * waiting for the next 5-minute tick. Wrapped in try/catch so a dashboard-side failure can
 * never block the maintenance-mode command itself from completing.
 */
class MaintenanceModePlugin
{
    public function __construct(
        private readonly HeartbeatSender $heartbeatSender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param bool $result
     * @return bool
     */
    public function afterSet(MaintenanceMode $subject, $result)
    {
        try {
            $this->heartbeatSender->send();
        } catch (Throwable $e) {
            $this->logger->warning(
                'StackGauge: instant heartbeat after a maintenance-mode change failed: ' . $e->getMessage()
            );
        }

        return $result;
    }
}
