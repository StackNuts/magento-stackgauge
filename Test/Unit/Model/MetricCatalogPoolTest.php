<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Model\MetricCatalogPool;
use StackNuts\StackGauge\Model\ReporterPool;

class MetricCatalogPoolTest extends TestCase
{
    /**
     * @param MetricDefinition[] $metrics
     */
    private function reporterWithMetrics(string $name, array $metrics): ReporterInterface
    {
        return new class ($name, $metrics) implements ReporterInterface, MetricCatalogInterface {
            public function __construct(private readonly string $name, private readonly array $metrics)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getStatus(): array
            {
                return [];
            }

            public function getTrackableMetrics(): array
            {
                return $this->metrics;
            }
        };
    }

    private function reporterWithoutMetrics(string $name): ReporterInterface
    {
        return new class ($name) implements ReporterInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getStatus(): array
            {
                return [];
            }
        };
    }

    private function throwingCatalogReporter(string $name): ReporterInterface
    {
        return new class ($name) implements ReporterInterface, MetricCatalogInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getStatus(): array
            {
                return [];
            }

            public function getTrackableMetrics(): array
            {
                throw new RuntimeException('boom');
            }
        };
    }

    public function testCollectsMetricsOnlyFromReportersThatDeclareThem(): void
    {
        $metric = new MetricDefinition(
            'disk.media.free_percent',
            'Disk Space: Media Free %',
            MetricDefinition::AGGREGATION_LATEST,
            MetricDefinition::OPERATOR_LT,
            10,
            15
        );

        $reporterPool = $this->createStub(ReporterPool::class);
        $reporterPool->method('getReporters')->willReturn([
            $this->reporterWithMetrics('disk', [$metric]),
            $this->reporterWithoutMetrics('core'),
        ]);

        $pool = new MetricCatalogPool($reporterPool, $this->createStub(LoggerInterface::class));

        $this->assertSame(['disk.media.free_percent' => $metric], $pool->collect());
    }

    public function testAFailingReporterIsSkippedWithoutBlockingOthers(): void
    {
        $metric = new MetricDefinition(
            'ok.metric',
            'OK',
            MetricDefinition::AGGREGATION_SUM,
            MetricDefinition::OPERATOR_GT,
            1,
            60
        );

        $reporterPool = $this->createStub(ReporterPool::class);
        $reporterPool->method('getReporters')->willReturn([
            $this->throwingCatalogReporter('broken'),
            $this->reporterWithMetrics('fine', [$metric]),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $pool = new MetricCatalogPool($reporterPool, $logger);

        $this->assertSame(['ok.metric' => $metric], $pool->collect());
    }

    public function testReturnsAnEmptyArrayWhenNoReporterDeclaresMetrics(): void
    {
        $reporterPool = $this->createStub(ReporterPool::class);
        $reporterPool->method('getReporters')->willReturn([$this->reporterWithoutMetrics('core')]);

        $pool = new MetricCatalogPool($reporterPool, $this->createStub(LoggerInterface::class));

        $this->assertSame([], $pool->collect());
    }
}
