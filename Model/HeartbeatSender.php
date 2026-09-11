<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use DateTimeImmutable;
use Magento\Framework\App\MaintenanceMode;

/**
 * Lightweight "alive + maintenance mode" ping, separate from the full ReportSender payload,
 * sent every few minutes by Cron\SendHeartbeat and instantly on a maintenance-mode change
 * via Plugin\App\MaintenanceModePlugin - so a site stuck in maintenance mode after a failed
 * deploy is visible well before the next hourly full report.
 */
class HeartbeatSender
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly Config $config,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly Transport $transport
    ) {
    }

    public function send(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendNow();
    }

    /**
     * Send a heartbeat unconditionally (ignores the "Enabled" toggle). Used
     * by admin Test Ping so connectivity can be verified mid-setup.
     */
    public function sendNow(): bool
    {
        $payload = [
            'type' => 'heartbeat',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
            'maintenance_mode' => $this->maintenanceMode->isOn(),
        ];

        return $this->transport->send($payload, 'heartbeat');
    }
}
