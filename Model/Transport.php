<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
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

    /**
     * Takes a CurlFactory rather than a Curl: Magento shares one Curl instance per process and
     * the headers set on it persist, so the Authorization/signature headers set here would
     * otherwise ride along on whatever the next caller of that shared client requests.
     *
     * @param Config $config
     * @param PayloadSigner $payloadSigner
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly PayloadSigner $payloadSigner,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sends $payload to the configured dashboard endpoint.
     *
     * Gzip-compressed above GZIP_THRESHOLD_BYTES and HMAC-signed if a secret is configured.
     *
     * @param array<string,mixed> $payload
     * @param string $logContext Short label for this send used in log/warning messages, e.g. "heartbeat".
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

        // The API key and signed store data must never cross the network in the clear.
        if (strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            $this->logger->warning(sprintf(
                'StackGauge: cannot send %s - the endpoint URL must use https://.',
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
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            $compressed = gzencode($body);
            if ($compressed !== false) {
                $wireBody = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        $curl = $this->curlFactory->create();
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
        ]);
        $curl->setHeaders($headers);

        try {
            $curl->post($endpoint, $wireBody);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'StackGauge: failed to send %s: %s',
                $logContext,
                $e->getMessage()
            ), ['exception' => $e]);
            return false;
        }

        $status = $curl->getStatus();
        if ($status >= 200 && $status < 300) {
            $this->logger->info(sprintf('StackGauge: %s sent successfully (HTTP %d).', $logContext, $status));
            return true;
        }

        $this->logger->warning(sprintf(
            'StackGauge: dashboard responded with HTTP %d for %s: %s',
            $status,
            $logContext,
            substr((string)$curl->getBody(), 0, 500)
        ));

        return false;
    }
}
