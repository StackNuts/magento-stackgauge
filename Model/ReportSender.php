<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Model\System\Config\Source\LogLevel;

/**
 * Orchestrates both the full-collection report and the config-sync send: build the payload,
 * log it, then send it. The send()/sendConfigSync() methods respect the admin "Enabled"
 * toggle; the sendNow()/sendConfigSyncNow() variants send unconditionally, for callers like
 * the admin Test Ping button where an explicit action should work even before "Enabled" is
 * switched on.
 */
class ReportSender
{
    /**
     * @param Config $config
     * @param PayloadBuilder $payloadBuilder
     * @param Transport $transport
     * @param LoggerInterface $logger
     * @param LogOffsetStore $logOffsetStore
     */
    public function __construct(
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly Transport $transport,
        private readonly LoggerInterface $logger,
        private readonly LogOffsetStore $logOffsetStore
    ) {
    }

    /**
     * The full-collection report payload for the given cadence tier, without sending it.
     *
     * A preview never advances the log read offsets: anything staged by an earlier build is
     * dropped first, and what this build stages is only committed by a later successful send.
     *
     * @param string $cadence One of Api\DeclaresCadenceInterface::CADENCE_*.
     * @param Slot|null $slot Run within the tier; null collects the whole tier.
     * @return array<string, mixed>
     */
    public function buildPayload(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY, ?Slot $slot = null): array
    {
        $this->logOffsetStore->discard();

        return $this->payloadBuilder->build($cadence, $slot);
    }

    /**
     * The config-sync payload, without sending it.
     *
     * @return array<string, mixed>
     */
    public function buildConfigSync(): array
    {
        return $this->payloadBuilder->buildConfigSync();
    }

    /**
     * Respects the admin "Enabled" toggle - this is what the hourly/daily cron jobs call.
     *
     * @param string $cadence One of Api\DeclaresCadenceInterface::CADENCE_*.
     * @param Slot|null $slot Run within the tier; null collects the whole tier.
     */
    public function send(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY, ?Slot $slot = null): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendNow($cadence, $slot);
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
     *
     * @param string $cadence One of Api\DeclaresCadenceInterface::CADENCE_*.
     * @param Slot|null $slot Run within the tier; null collects the whole tier.
     */
    public function sendNow(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY, ?Slot $slot = null): bool
    {
        $payload = $this->buildPayload($cadence, $slot);
        $this->logPayload("full report ({$cadence})", $payload);

        $sent = $this->transport->send($payload, "report ({$cadence})");
        if ($sent) {
            // Only now that the dashboard accepted the report do its log lines count as delivered.
            $this->logOffsetStore->commit();
        } else {
            $this->logOffsetStore->discard();
        }

        return $sent;
    }

    /**
     * Sends the config-sync payload unconditionally, ignoring the "Enabled" toggle.
     */
    public function sendConfigSyncNow(): bool
    {
        $payload = $this->buildConfigSync();
        $this->logPayload('config sync', $payload);

        return $this->transport->send($payload, 'config sync');
    }

    /**
     * Writes the payload to the module log, but only builds the JSON when Info is enabled -
     * encoding a whole report just to have the logger drop it is wasted work. Invalid UTF-8
     * is substituted so one bad byte can't turn the logged payload into an empty string.
     *
     * @param string $label
     * @param array<string, mixed> $payload
     */
    private function logPayload(string $label, array $payload): void
    {
        $level = $this->config->getLogLevel();
        if ($level === LogLevel::LEVEL_OFF || $level > MonologLogger::INFO) {
            return;
        }

        $this->logger->info(
            "StackGauge: {$label} "
            . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
    }
}
