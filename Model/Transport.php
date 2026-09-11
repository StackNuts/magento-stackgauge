<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared HTTPS delivery for both the full-collection report and the lightweight heartbeat -
 * same auth headers, HMAC signing, and tight timeouts, since a slow/unreachable dashboard
 * must never hang a site's cron.
 */
class Transport
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TOTAL_TIMEOUT_SECONDS = 10;

    /**
     * Below this size gzip's own overhead (header/footer/table) isn't worth paying - the
     * tiny heartbeat body is well under it, the full report (hundreds of KB once a site has
     * a few hundred modules) is well over it.
     */
    private const GZIP_THRESHOLD_BYTES = 1024;

    public function __construct(
        private readonly Config $config,
        private readonly PayloadSigner $payloadSigner,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(array $payload, string $logContext): bool
    {
        $endpoint = $this->config->getEndpointUrl();
        $apiKey = $this->config->getApiKey();

        if (!$endpoint || !$apiKey) {
            $this->logger->warning(sprintf(
                'StackGauge: cannot send %s - endpoint URL or API key is not configured.',
                $logContext
            ));
            return false;
        }

        $body = $this->json->serialize($payload);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
            'X-StackGauge-Site-Id' => (string)($payload['site']['identifier'] ?? ''),
        ];

        $hmacSecret = $this->config->getHmacSecret();
        if ($hmacSecret) {
            // Sign the raw, uncompressed body - the dashboard decompresses before verifying,
            // so the signed bytes never depend on gzip's own (non-deterministic) output.
            $headers['X-StackGauge-Signature'] = $this->payloadSigner->sign($body, $hmacSecret);
        }

        $wireBody = $body;
        if (strlen($body) >= self::GZIP_THRESHOLD_BYTES) {
            $compressed = gzencode($body);
            if ($compressed !== false) {
                $wireBody = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        $this->curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
        ]);
        $this->curl->setHeaders($headers);

        try {
            $this->curl->post($endpoint, $wireBody);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'StackGauge: failed to send %s: %s',
                $logContext,
                $e->getMessage()
            ), ['exception' => $e]);
            return false;
        }

        $status = $this->curl->getStatus();
        if ($status >= 200 && $status < 300) {
            $this->logger->info(sprintf('StackGauge: %s sent successfully (HTTP %d).', $logContext, $status));
            return true;
        }

        $this->logger->warning(sprintf(
            'StackGauge: dashboard responded with HTTP %d for %s: %s',
            $status,
            $logContext,
            substr((string)$this->curl->getBody(), 0, 500)
        ));

        return false;
    }
}
