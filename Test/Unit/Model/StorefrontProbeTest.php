<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\StorefrontProbe;

class StorefrontProbeTest extends TestCase
{
    private function probe(string $baseUrl, int $status, string $body): StorefrontProbe
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with('web/unsecure/base_url')->willReturn($baseUrl);

        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);

        return new StorefrontProbe($scopeConfig, $curl);
    }

    public function testNotAttemptedWhenNoBaseUrlConfigured(): void
    {
        $result = $this->probe('', 0, '')->probe();

        $this->assertFalse($result['attempted']);
        $this->assertFalse($result['reachable']);
    }

    public function testHealthyHomepageIsReachableAndNotFlagged(): void
    {
        $result = $this->probe('https://example.test/', 200, '<html><title>Home Page</title></html>')->probe();

        $this->assertTrue($result['attempted']);
        $this->assertTrue($result['reachable']);
        $this->assertSame(200, $result['http_status']);
        $this->assertGreaterThanOrEqual(0, $result['response_time_ms']);
        $this->assertFalse($result['looks_blocked']);
        $this->assertFalse($result['looks_like_error_page']);
    }

    public function testDetectsMagentosOwnFatalErrorPage(): void
    {
        $result = $this->probe(
            'https://example.test/',
            500,
            '<html><h1>There has been an error processing your request</h1></html>'
        )->probe();

        $this->assertTrue($result['looks_like_error_page']);
        $this->assertFalse($result['looks_blocked']);
    }

    /**
     * A site fronted by Cloudflare (or similar) gets a cf-ray-style header on every response,
     * not just challenges - this reporter only ever looks at body content, not headers, so a
     * perfectly normal cf-fronted page must never be flagged as blocked just because it's
     * proxied.
     */
    public function testCloudflareHeaderAloneDoesNotCountAsBlocked(): void
    {
        $result = $this->probe('https://example.test/', 200, '<html><title>Home Page</title></html>')->probe();

        $this->assertFalse($result['looks_blocked']);
    }

    public function testDetectsAChallengePageAsBlockedNotDown(): void
    {
        $result = $this->probe(
            'https://example.test/',
            403,
            '<html><title>Just a moment...</title><body>Checking your browser before accessing</body></html>'
        )->probe();

        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['looks_blocked']);
        $this->assertFalse($result['looks_like_error_page']);
    }

    public function testConnectionFailureIsReportedAsAttemptedButUnreachable(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('https://example.test/');

        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willThrowException(new \Exception('Connection timed out'));

        $probe = new StorefrontProbe($scopeConfig, $curl);
        $result = $probe->probe();

        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['reachable']);
        $this->assertSame(0, $result['http_status']);
    }

    public function testCheckExposedPathsFlagsOnlyReachablePaths(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('https://example.test');

        $statusByUrl = [
            'https://example.test/.git/HEAD' => 200,
            'https://example.test/composer.lock' => 404,
        ];

        $lastUrl = null;
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willReturnCallback(function (string $url) use (&$lastUrl): void {
            $lastUrl = $url;
        });
        $curl->method('getStatus')->willReturnCallback(
            function () use (&$lastUrl, $statusByUrl): int {
                return $statusByUrl[$lastUrl] ?? 404;
            }
        );

        $probe = new StorefrontProbe($scopeConfig, $curl);
        $results = $probe->checkExposedPaths();

        $this->assertTrue($results['.git/HEAD']);
        $this->assertFalse($results['composer.lock']);
        // Every other checked path defaults to 404 in this test's fake, so not exposed.
        $this->assertFalse($results['app/etc/env.php']);
    }

    public function testCheckExposedPathsReturnsEmptyWhenNoBaseUrlConfigured(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');
        $curl = $this->createMock(Curl::class);

        $probe = new StorefrontProbe($scopeConfig, $curl);

        $this->assertSame([], $probe->checkExposedPaths());
    }
}
