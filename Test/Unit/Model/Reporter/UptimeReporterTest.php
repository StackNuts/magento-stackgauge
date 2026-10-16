<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\UptimeReporter;
use StackNuts\StackGauge\Model\StorefrontProbe;
use StackNuts\StackGauge\Model\TlsCertificateInspector;

class UptimeReporterTest extends TestCase
{
    private const HEALTHY_PROBE_RESULT = [
        'attempted' => true,
        'reachable' => true,
        'http_status' => 200,
        'response_time_ms' => 350,
        'looks_blocked' => false,
        'looks_like_error_page' => false,
    ];

    /**
     * @param array{
     *     attempted: bool,
     *     reachable: bool,
     *     http_status: int,
     *     response_time_ms: int,
     *     looks_blocked: bool,
     *     looks_like_error_page: bool
     * } $probeResult
     * @param array<string,string> $secureBaseUrlsByStoreId store id => web/secure/base_url
     * @param array<string,array<string,mixed>> $tlsResultsByHost host => TlsCertificateInspector::inspect() result
     */
    private function reporter(
        array $probeResult,
        bool $maintenanceOn = false,
        array $secureBaseUrlsByStoreId = [],
        array $tlsResultsByHost = []
    ): UptimeReporter {
        $probe = $this->createMock(StorefrontProbe::class);
        $probe->method('probe')->willReturn($probeResult);

        $maintenance = $this->createMock(MaintenanceMode::class);
        $maintenance->method('isOn')->willReturn($maintenanceOn);

        $tlsInspector = $this->createMock(TlsCertificateInspector::class);
        $tlsInspector->method('inspect')->willReturnCallback(
            static fn (string $host): array => $tlsResultsByHost[$host]
        );

        $stores = [];
        foreach (array_keys($secureBaseUrlsByStoreId) as $storeId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn((int)$storeId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scopeType, $scopeCode): string =>
                $path === 'web/secure/base_url' ? ($secureBaseUrlsByStoreId[(string)$scopeCode] ?? '') : ''
        );

        return new UptimeReporter($probe, $maintenance, $tlsInspector, $storeManager, $scopeConfig, new Field(), new Section());
    }

    public function testHealthySiteReportsReachableAsOne(): void
    {
        $fields = $this->reporter(self::HEALTHY_PROBE_RESULT)->getStatus()['general']->getFields();

        $this->assertSame(1, $fields['reachable']->getValue());
        $this->assertSame(200, $fields['http_status']->getValue());
        $this->assertSame(350, $fields['response_time_ms']->getValue());
        $this->assertFalse($fields['looks_blocked']->getValue());
        $this->assertFalse($fields['looks_like_error_page']->getValue());
    }

    public function testUnreachableSiteReportsReachableAsZero(): void
    {
        $fields = $this->reporter([
            'attempted' => true,
            'reachable' => false,
            'http_status' => 0,
            'response_time_ms' => 0,
            'looks_blocked' => false,
            'looks_like_error_page' => false,
        ])->getStatus()['general']->getFields();

        $this->assertSame(0, $fields['reachable']->getValue());
    }

    public function testDeclaresTheReachableTrackableMetric(): void
    {
        $metrics = $this->reporter([
            'attempted' => false,
            'reachable' => false,
            'http_status' => 0,
            'response_time_ms' => 0,
            'looks_blocked' => false,
            'looks_like_error_page' => false,
        ])->getTrackableMetrics();

        $this->assertCount(4, $metrics);
        $this->assertSame('uptime.reachable', $metrics[0]->getMetricKey());
        $this->assertSame('min', $metrics[0]->getAggregation());
        $this->assertSame('lt', $metrics[0]->getDefaultOperator());
        $this->assertSame(1, $metrics[0]->getDefaultThreshold());
    }

    public function testDeclaresTheHealthSection(): void
    {
        $reporter = $this->reporter(self::HEALTHY_PROBE_RESULT);

        $this->assertSame('health', $reporter->getSection());
    }

    public function testReportsMaintenanceModeState(): void
    {
        $off = $this->reporter(self::HEALTHY_PROBE_RESULT)->getStatus()['general']->getFields();
        $on = $this->reporter(self::HEALTHY_PROBE_RESULT, true)->getStatus()['general']->getFields();

        $this->assertFalse($off['maintenance_mode']->getValue());
        $this->assertTrue($on['maintenance_mode']->getValue());
    }

    public function testDeclaresTheResponseTimeTrackableMetric(): void
    {
        $metrics = $this->reporter([
            'attempted' => false,
            'reachable' => false,
            'http_status' => 0,
            'response_time_ms' => 0,
            'looks_blocked' => false,
            'looks_like_error_page' => false,
        ])->getTrackableMetrics();

        $this->assertSame('uptime.response_time_ms', $metrics[1]->getMetricKey());
        $this->assertSame('avg', $metrics[1]->getAggregation());
        $this->assertSame('gt', $metrics[1]->getDefaultOperator());
    }

    public function testDeclaresTheTlsTrackableMetrics(): void
    {
        $metrics = $this->reporter(self::HEALTHY_PROBE_RESULT)->getTrackableMetrics();

        $this->assertSame('uptime.tls_days_remaining', $metrics[2]->getMetricKey());
        $this->assertSame('latest', $metrics[2]->getAggregation());
        $this->assertSame('lt', $metrics[2]->getDefaultOperator());
        $this->assertSame(21, $metrics[2]->getDefaultThreshold());
        $this->assertSame(7, $metrics[2]->getCriticalThreshold());

        $this->assertSame('uptime.tls_untrusted_certificate', $metrics[3]->getMetricKey());
        $this->assertSame('critical', $metrics[3]->getSeverity());
    }

    public function testNoHttpsBaseUrlReportsNotAttemptedAndNoTrackableField(): void
    {
        $status = $this->reporter(self::HEALTHY_PROBE_RESULT, secureBaseUrlsByStoreId: [
            '1' => 'http://plain.example.test/',
        ])->getStatus();

        $generalFields = $status['general']->getFields();
        $this->assertFalse($generalFields['tls_attempted']->getValue());
        $this->assertArrayNotHasKey('tls_days_remaining', $generalFields);
        $this->assertSame([], $status['tls']->getRows());
    }

    public function testTwoHostsReportTheMinimumDaysRemaining(): void
    {
        $status = $this->reporter(
            self::HEALTHY_PROBE_RESULT,
            secureBaseUrlsByStoreId: [
                '1' => 'https://one.example.test/',
                '2' => 'https://two.example.test/',
            ],
            tlsResultsByHost: [
                'one.example.test' => $this->tlsResult(days: 45),
                'two.example.test' => $this->tlsResult(days: 10),
            ]
        )->getStatus();

        $generalFields = $status['general']->getFields();
        $this->assertTrue($generalFields['tls_attempted']->getValue());
        $this->assertSame(10, $generalFields['tls_days_remaining']->getValue());
        $this->assertCount(2, $status['tls']->getRows());
    }

    public function testUntrustedCertificateSetsTheFindingTrue(): void
    {
        $status = $this->reporter(
            self::HEALTHY_PROBE_RESULT,
            secureBaseUrlsByStoreId: ['1' => 'https://self-signed.example.test/'],
            tlsResultsByHost: [
                'self-signed.example.test' => $this->tlsResult(days: 90, trusted: false),
            ]
        )->getStatus();

        $this->assertTrue($status['general']->getFields()['tls_untrusted_certificate']->getValue());
    }

    public function testTrustedCertificateLeavesTheFindingFalse(): void
    {
        $status = $this->reporter(
            self::HEALTHY_PROBE_RESULT,
            secureBaseUrlsByStoreId: ['1' => 'https://example.test/'],
            tlsResultsByHost: [
                'example.test' => $this->tlsResult(days: 90, trusted: true),
            ]
        )->getStatus();

        $this->assertFalse($status['general']->getFields()['tls_untrusted_certificate']->getValue());
    }

    /**
     * @return array<string,mixed>
     */
    private function tlsResult(int $days, bool $trusted = true, bool $connected = true): array
    {
        return [
            'attempted' => true,
            'connected' => $connected,
            'trusted' => $trusted,
            'expires_at' => '2026-12-01T00:00:00+00:00',
            'days_remaining' => $days,
            'issuer' => "Let's Encrypt",
            'subject' => 'example.test',
            'error' => $trusted ? null : 'Certificate failed verification.',
        ];
    }
}
