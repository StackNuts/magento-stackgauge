<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\UptimeReporter;
use StackNuts\StackGauge\Model\StorefrontProbe;

class UptimeReporterTest extends TestCase
{
    /**
     * @param array{
     *     attempted: bool,
     *     reachable: bool,
     *     http_status: int,
     *     response_time_ms: int,
     *     looks_blocked: bool,
     *     looks_like_error_page: bool
     * } $probeResult
     */
    private function reporter(array $probeResult): UptimeReporter
    {
        $probe = $this->createMock(StorefrontProbe::class);
        $probe->method('probe')->willReturn($probeResult);

        return new UptimeReporter($probe, new Field(), new Section());
    }

    public function testHealthySiteReportsReachableAsOne(): void
    {
        $fields = $this->reporter([
            'attempted' => true,
            'reachable' => true,
            'http_status' => 200,
            'response_time_ms' => 350,
            'looks_blocked' => false,
            'looks_like_error_page' => false,
        ])->getStatus()['general']->getFields();

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

        $this->assertCount(2, $metrics);
        $this->assertSame('uptime.reachable', $metrics[0]->getMetricKey());
        $this->assertSame('min', $metrics[0]->getAggregation());
        $this->assertSame('lt', $metrics[0]->getDefaultOperator());
        $this->assertSame(1, $metrics[0]->getDefaultThreshold());
    }

    public function testDeclaresTheHealthSection(): void
    {
        $probe = $this->createMock(StorefrontProbe::class);
        $reporter = new UptimeReporter($probe, new Field(), new Section());

        $this->assertSame('health', $reporter->getSection());
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
}
