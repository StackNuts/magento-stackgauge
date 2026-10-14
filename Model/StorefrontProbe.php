<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
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
     * Each maps to a pattern the response body must match to count as exposed - what that
     * file genuinely looks like. A 200 alone isn't proof: plenty of hosts answer every unknown
     * path with a 200 catch-all or homepage, which would otherwise read as "everything is
     * exposed".
     *
     * @var array<string, string>
     */
    private const SENSITIVE_PATHS = [
        '.git/HEAD' => '#^(ref: refs/|[0-9a-f]{40})#',
        '.git/config' => '#^\s*\[core\]#m',
        'composer.json' => '#^\s*\{#',
        'composer.lock' => '#^\s*\{#',
        'app/etc/env.php' => '#<\?php|[\'"](db|crypt|backend)[\'"]\s*=>#',
        'var/log/system.log' => '#^\[\d{4}-\d{2}-\d{2}[T ]#m',
        'var/log/exception.log' => '#^\[\d{4}-\d{2}-\d{2}[T ]#m',
        'pub/media/.htaccess' => '#^\s*(Options|php_flag|RewriteEngine|Require|Order|<IfModule)#mi',
    ];

    /**
     * A fresh Curl client per request rather than an injected shared one: Magento shares a
     * single Curl instance across the whole process, and headers set on it stick - so a probe
     * running after Transport::send() in the same cron run used to go out carrying the
     * dashboard's Authorization header and signature.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param CurlFactory $curlFactory
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory
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
            $curl = $this->newClient();
            $startedAt = microtime(true);
            $curl->get($baseUrl);
            $responseTimeMs = (int)round((microtime(true) - $startedAt) * 1000);

            $status = $curl->getStatus();
            $body = (string)$curl->getBody();

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
     * Checks each of SENSITIVE_PATHS for reachability from the public storefront. Only a 2xx
     * response whose body actually looks like that file counts as exposed - a redirect (an
     * http->https hop, or a "not found" redirect to the homepage) or a catch-all 200 page is
     * not. Redirects aren't followed.
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
        foreach (self::SENSITIVE_PATHS as $path => $contentPattern) {
            $results[$path] = $this->isPathExposed($baseUrl, $path, $contentPattern);
        }

        return $results;
    }

    /**
     * This store's own base URL, or null if not configured yet. The secure URL first - it's
     * what real visitors end up on, and probing the http:// one would mostly just measure an
     * http->https redirect.
     */
    private function resolveBaseUrl(): ?string
    {
        foreach (['web/secure/base_url', 'web/unsecure/base_url'] as $path) {
            $url = (string)$this->scopeConfig->getValue($path, StoreScopeInterface::SCOPE_STORE);
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * A new client with this probe's timeouts - see the constructor for why it's never shared.
     */
    private function newClient(): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
        ]);

        return $curl;
    }

    /**
     * Whether $path is served under $baseUrl with a 2xx status and a body matching
     * $contentPattern.
     *
     * @param string $baseUrl
     * @param string $path
     * @param string $contentPattern
     */
    private function isPathExposed(string $baseUrl, string $path, string $contentPattern): bool
    {
        try {
            $curl = $this->newClient();
            $curl->get(rtrim($baseUrl, '/') . '/' . ltrim($path, '/'));
            $status = $curl->getStatus();

            return $status >= 200 && $status < 300
                && preg_match($contentPattern, (string)$curl->getBody()) === 1;
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
