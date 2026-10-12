<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface as StoreScopeInterface;
use Throwable;

/**
 * Curls this store's own homepage from the server it runs on, and tells apart three outcomes
 * that a naive "did the request succeed" check can't: the site responding normally, Magento
 * itself serving a genuine fatal-error page, and something in front of the site - a WAF/CDN
 * challenge, most commonly - blocking the probe before it ever reaches Magento. Conflating the
 * last two is a classic uptime-tool false positive: an external monitor getting
 * Cloudflare-challenged looks identical to the site actually being down unless the two are
 * told apart, and a site fronted by Cloudflare (or similar) gets a cf-ray-style header on
 * every single response, not just challenges - so that header's mere presence can't be the
 * signal either. This only ever looks at response status and body content.
 */
class StorefrontProbe
{
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TOTAL_TIMEOUT_SECONDS = 5;

    /**
     * pub/errors/default/report.phtml's own <h1> - the literal text a real visitor sees on
     * Magento's genuine production-mode fatal error page, not a guessed string.
     */
    private const MAGENTO_ERROR_MARKER = 'There has been an error processing your request';

    /**
     * Body-content markers for common WAF/CDN interstitial (challenge/block) pages.
     * Deliberately not exhaustive or vendor-specific beyond the most common case (Cloudflare) -
     * the goal is to catch the common "the probe never reached Magento at all" case, not to
     * fingerprint every WAF in existence.
     *
     * @var list<string>
     */
    private const CHALLENGE_MARKERS = [
        'Just a moment',
        'Checking your browser',
        'Attention Required! | Cloudflare',
        '/cdn-cgi/challenge-platform/',
    ];

    /**
     * Paths that should never resolve to anything from the public storefront - most only
     * become reachable when the webserver's docroot points at the project root instead of
     * pub/ (a classic deploy misconfiguration), not because Magento itself serves them.
     * pub/media/.htaccess is the exception - it's always under the real docroot, so its mere
     * reachability means Apache isn't honoring the .htaccess Magento ships there at all (e.g.
     * AllowOverride None), a real sign the "deny PHP execution here" rule isn't active either.
     *
     * @var list<string>
     */
    private const SENSITIVE_PATHS = [
        '.git/HEAD',
        '.git/config',
        'composer.json',
        'composer.lock',
        'app/etc/env.php',
        'var/log/system.log',
        'var/log/exception.log',
        'pub/media/.htaccess',
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $curl
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Curl $curl
    ) {
    }

    /**
     * Probes this store's own base URL.
     *
     * "attempted" is false only when there's no base URL to probe at all (store not fully
     * configured yet) - every other outcome, including a connection failure, is a real result
     * with attempted=true.
     *
     * @return array{
     *     attempted: bool,
     *     reachable: bool,
     *     http_status: int,
     *     response_time_ms: int,
     *     looks_blocked: bool,
     *     looks_like_error_page: bool
     * }
     */
    public function probe(): array
    {
        $baseUrl = $this->resolveBaseUrl();
        if ($baseUrl === null) {
            return $this->result(attempted: false, reachable: false, status: 0, body: '');
        }

        try {
            $this->curl->setOptions([
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
            ]);
            $startedAt = microtime(true);
            $this->curl->get($baseUrl);
            $responseTimeMs = (int)round((microtime(true) - $startedAt) * 1000);

            $status = $this->curl->getStatus();
            $body = (string)$this->curl->getBody();

            return $this->result(
                attempted: true,
                reachable: $status > 0,
                status: $status,
                body: $body,
                responseTimeMs: $status > 0 ? $responseTimeMs : 0
            );
        } catch (Throwable) {
            // A connection failure (DNS, refused, timeout, ...) is itself a meaningful result,
            // not a reporter failure to swallow - "couldn't even connect" is exactly what this
            // probe exists to surface.
            return $this->result(attempted: true, reachable: false, status: 0, body: '');
        }
    }

    /**
     * Checks each of SENSITIVE_PATHS for reachability from the public storefront. A 2xx/3xx
     * response counts as exposed; a 404 (or any other response, or a connection failure)
     * counts as not exposed - this only ever looks at the status code, never the body, so a
     * misconfigured host that serves 200 for every path (an SPA-style catch-all) would read
     * every path as "exposed", a known false-positive mode worth knowing about rather than
     * a reason to add body-sniffing complexity here.
     *
     * Deliberately separate from probe() and never called from the heartbeat - these are slow
     * -changing deployment facts, not a minute-to-minute availability signal, and running 8
     * extra requests every 5 minutes forever would be wasted load for no benefit. Called at
     * most once a day, from Reporter\SecurityReporter.
     *
     * @return array<string, bool> path => exposed
     */
    public function checkExposedPaths(): array
    {
        $baseUrl = $this->resolveBaseUrl();
        if ($baseUrl === null) {
            return [];
        }

        $results = [];
        foreach (self::SENSITIVE_PATHS as $path) {
            $results[$path] = $this->isPathExposed($baseUrl, $path);
        }

        return $results;
    }

    /**
     * This store's own base URL, or null if not configured yet.
     */
    private function resolveBaseUrl(): ?string
    {
        $url = (string)$this->scopeConfig->getValue('web/unsecure/base_url', StoreScopeInterface::SCOPE_STORE);

        return $url !== '' ? $url : null;
    }

    /**
     * Whether $path resolves to something other than a 404 under $baseUrl.
     *
     * @param string $baseUrl
     * @param string $path
     */
    private function isPathExposed(string $baseUrl, string $path): bool
    {
        try {
            $this->curl->setOptions([
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
            ]);
            $this->curl->get(rtrim($baseUrl, '/') . '/' . ltrim($path, '/'));
            $status = $this->curl->getStatus();

            return $status >= 200 && $status < 400;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Assembles probe()'s return shape and classifies $body.
     *
     * @param bool $attempted
     * @param bool $reachable
     * @param int $status
     * @param string $body
     * @param int $responseTimeMs Wall time for the whole request (connect through full body) -
     *     0 when the probe wasn't attempted or got no HTTP response at all.
     * @return array{
     *     attempted: bool,
     *     reachable: bool,
     *     http_status: int,
     *     response_time_ms: int,
     *     looks_blocked: bool,
     *     looks_like_error_page: bool
     * }
     */
    private function result(
        bool $attempted,
        bool $reachable,
        int $status,
        string $body,
        int $responseTimeMs = 0
    ): array {
        return [
            'attempted' => $attempted,
            'reachable' => $reachable,
            'http_status' => $status,
            'response_time_ms' => $responseTimeMs,
            'looks_blocked' => $this->containsAny($body, self::CHALLENGE_MARKERS),
            'looks_like_error_page' => str_contains($body, self::MAGENTO_ERROR_MARKER),
        ];
    }

    /**
     * Whether $haystack contains any of $markers.
     *
     * @param string $haystack
     * @param list<string> $markers
     */
    private function containsAny(string $haystack, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
