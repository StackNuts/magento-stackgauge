<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\App\MaintenanceMode;

/**
 * Lightweight "alive + maintenance mode [+ storefront reachability]" ping, separate from the
 * full ReportSender payload, sent every few minutes by Cron\SendHeartbeat and instantly on a
 * maintenance-mode change via Plugin\App\MaintenanceModePlugin - so a site stuck in
 * maintenance mode (or actually down) after a failed deploy is visible well before the next
 * hourly full report.
 *
 * The storefront probe (see Model\StorefrontProbe) also exists as Reporter\UptimeReporter,
 * collected again hourly alongside every other reporter - not because this copy is somehow
 * insufficient, but because a trackable alert metric can only ever attach to a reporter
 * actually registered with ReporterPool (see Model\MetricCatalogPool), which this class
 * deliberately isn't. This copy stays for what a reporter can't do: showing up well before the
 * next hourly report, the same reason maintenance_mode is here too.
 */
class HeartbeatSender
{
    private const SCHEMA_VERSION = '1.1';

    /**
     * @param Config $config
     * @param MaintenanceMode $maintenanceMode
     * @param StorefrontProbe $storefrontProbe
     * @param Transport $transport
     * @param PayloadEnvelope $envelope
     */
    public function __construct(
        private readonly Config $config,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly StorefrontProbe $storefrontProbe,
        private readonly Transport $transport,
        private readonly PayloadEnvelope $envelope
    ) {
    }

    /**
     * Sends a heartbeat, respecting the admin "Enabled" toggle.
     */
    public function send(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendNow();
    }

    /**
     * Sends a heartbeat unconditionally (ignores the "Enabled" toggle).
     *
     * Used by admin Test Ping so connectivity can be verified mid-setup.
     */
    public function sendNow(): bool
    {
        $payload = $this->envelope->create('heartbeat', self::SCHEMA_VERSION, withModuleVersion: false) + [
            'maintenance_mode' => $this->maintenanceMode->isOn(),
        ];

        if ($this->config->isSelfProbeEnabled()) {
            $payload['storefront_probe'] = $this->storefrontProbe->probe();
        }

        return $this->transport->send($payload, 'heartbeat');
    }
}
