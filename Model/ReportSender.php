<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

/**
 * Orchestrates both the full-collection report and the config-sync send: build the payload,
 * log it, then send it. The send()/sendConfigSync() methods respect the admin "Enabled"
 * toggle; the sendNow()/sendConfigSyncNow() variants send unconditionally, for callers like
 * the admin Test Ping button where an explicit action should work even before "Enabled" is
 * switched on.
 */
class ReportSender
{
    public function __construct(
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly Transport $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): array
    {
        return $this->payloadBuilder->build($cadence);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConfigSync(): array
    {
        return $this->payloadBuilder->buildConfigSync();
    }

    /**
     * Respects the admin "Enabled" toggle - this is what the hourly/daily cron jobs call.
     */
    public function send(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendNow($cadence);
    }

    /**
     * Respects the admin "Enabled" toggle - this is what Cron\SendConfigSync calls.
     */
    public function sendConfigSync(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendConfigSyncNow();
    }

    /**
     * Sends unconditionally, ignoring the "Enabled" toggle. Still requires an endpoint URL
     * and API key to be configured for the actual HTTP send (enforced by Transport) - but
     * the payload is built and logged either way, so with Log Level set to "Info" the report
     * is visible in var/log/stacknuts_stackgauge.log even without a configured endpoint.
     */
    public function sendNow(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): bool
    {
        $payload = $this->buildPayload($cadence);
        $this->logger->info("StackGauge: full report ({$cadence}) " . json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $this->transport->send($payload, "report ({$cadence})");
    }

    /**
     * Sends the config-sync payload unconditionally, ignoring the "Enabled" toggle.
     */
    public function sendConfigSyncNow(): bool
    {
        $payload = $this->buildConfigSync();
        $this->logger->info('StackGauge: config sync ' . json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $this->transport->send($payload, 'config sync');
    }
}
