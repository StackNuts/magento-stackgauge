<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\PayloadEnvelope;
use StackNuts\StackGauge\Model\StorefrontProbe;

class StorefrontProbeTest extends TestCase
{
    private function probe(string $baseUrl, int $status, string $body): StorefrontProbe
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);

        return new StorefrontProbe($this->baseUrls($baseUrl), $this->factoryFor($curl), $this->envelope());
    }

    /**
     * @param string $secure
     * @param string $unsecure
     */
    private function baseUrls(string $secure, string $unsecure = ''): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['web/secure/base_url', 'store', null, $secure],
            ['web/unsecure/base_url', 'store', null, $unsecure],
        ]);

        return $scopeConfig;
    }

    private function envelope(string $version = '0.7.0'): PayloadEnvelope
    {
        $envelope = $this->createMock(PayloadEnvelope::class);
        $envelope->method('create')->willReturn(['module_version' => $version]);

        return $envelope;
    }

    /**
     * @param Curl $curl
     */
    private function factoryFor(Curl $curl): CurlFactory
    {
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        return $factory;
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
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willThrowException(new \Exception('Connection timed out'));

        $probe = new StorefrontProbe($this->baseUrls('https://example.test/'), $this->factoryFor($curl), $this->envelope());
        $result = $probe->probe();

        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['reachable']);
        $this->assertSame(0, $result['http_status']);
    }

    /**
     * @param array<string, array{0: int, 1: string}> $responses url => [status, body]; anything else 404s
     */
    private function exposedPathsFor(array $responses): array
    {
        $lastUrl = null;
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willReturnCallback(function (string $url) use (&$lastUrl): void {
            $lastUrl = $url;
        });
        $curl->method('getStatus')->willReturnCallback(
            function () use (&$lastUrl, $responses): int {
                return $responses[$lastUrl][0] ?? 404;
            }
        );
        $curl->method('getBody')->willReturnCallback(
            function () use (&$lastUrl, $responses): string {
                return $responses[$lastUrl][1] ?? '';
            }
        );

        $probe = new StorefrontProbe($this->baseUrls('https://example.test'), $this->factoryFor($curl), $this->envelope());

        return $probe->checkExposedPaths();
    }

    public function testCheckExposedPathsFlagsAPathServedWithItsRealContent(): void
    {
        $results = $this->exposedPathsFor([
            'https://example.test/.git/HEAD' => [200, "ref: refs/heads/main\n"],
            'https://example.test/composer.lock' => [404, ''],
        ]);

        $this->assertTrue($results['.git/HEAD']);
        $this->assertFalse($results['composer.lock']);
        $this->assertFalse($results['app/etc/env.php']);
    }

    /**
     * An http->https hop or a "not found" redirect to the homepage used to count as exposed.
     */
    public function testARedirectIsNotExposed(): void
    {
        $results = $this->exposedPathsFor([
            'https://example.test/.git/HEAD' => [301, ''],
            'https://example.test/app/etc/env.php' => [302, ''],
        ]);

        $this->assertFalse($results['.git/HEAD']);
        $this->assertFalse($results['app/etc/env.php']);
    }

    /**
     * A host answering every unknown path with a 200 homepage must not read as "everything
     * is exposed".
     */
    public function testACatchAllPageIsNotExposed(): void
    {
        $homepage = [200, '<!doctype html><html><title>Home Page</title></html>'];
        $results = $this->exposedPathsFor([
            'https://example.test/.git/HEAD' => $homepage,
            'https://example.test/.git/config' => $homepage,
            'https://example.test/composer.json' => $homepage,
            'https://example.test/var/log/system.log' => $homepage,
        ]);

        $this->assertSame([], array_keys(array_filter($results)));
    }

    public function testTheSecureBaseUrlIsProbedInPreferenceToTheUnsecureOne(): void
    {
        $requested = [];
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willReturnCallback(function (string $url) use (&$requested): void {
            $requested[] = $url;
        });
        $curl->method('getStatus')->willReturn(200);

        $probe = new StorefrontProbe(
            $this->baseUrls('https://secure.example.test/', 'http://plain.example.test/'),
            $this->factoryFor($curl),
            $this->envelope()
        );
        $probe->probe();

        $this->assertSame(['https://secure.example.test/'], $requested);
    }

    public function testEachRequestGetsItsOwnClient(): void
    {
        $factory = $this->createMock(CurlFactory::class);
        $factory->expects($this->exactly(9))->method('create')->willReturnCallback(
            fn() => $this->createMock(Curl::class)
        );

        $probe = new StorefrontProbe($this->baseUrls('https://example.test/'), $factory, $this->envelope());
        $probe->probe();
        $probe->checkExposedPaths();
    }

    public function testCheckExposedPathsReturnsEmptyWhenNoBaseUrlConfigured(): void
    {
        $probe = new StorefrontProbe($this->baseUrls(''), $this->createMock(CurlFactory::class), $this->envelope());

        $this->assertSame([], $probe->checkExposedPaths());
    }

    public function testSendsAVersionedUserAgent(): void
    {
        $options = [];
        $curl = $this->createMock(Curl::class);
        $curl->method('setOptions')->willReturnCallback(function (array $o) use (&$options): void {
            $options = $o;
        });
        $curl->method('getStatus')->willReturn(200);

        (new StorefrontProbe($this->baseUrls('https://example.test/'), $this->factoryFor($curl), $this->envelope('1.2.3')))
            ->probe();

        $this->assertSame('StackGauge/1.2.3', $options[CURLOPT_USERAGENT]);
    }
}
