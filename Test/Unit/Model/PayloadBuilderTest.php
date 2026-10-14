<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\MetricCatalogPool;
use StackNuts\StackGauge\Model\PayloadBuilder;
use StackNuts\StackGauge\Model\PayloadEnvelope;
use StackNuts\StackGauge\Model\ReporterPool;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class PayloadBuilderTest extends TestCase
{
    private function builder(
        ?ReporterPool $reporterPool = null,
        ?MetricCatalogPool $metricCatalogPool = null,
        ?Config $config = null,
        ?ComposerLockReader $composerLockReader = null
    ): PayloadBuilder {
        $reporterPool ??= $this->createStub(ReporterPool::class);
        $metricCatalogPool ??= $this->createStub(MetricCatalogPool::class);
        $config ??= $this->createStub(Config::class);
        $composerLockReader ??= $this->createStub(ComposerLockReader::class);

        return new PayloadBuilder($reporterPool, $metricCatalogPool, new PayloadEnvelope($config, $composerLockReader));
    }

    private function composerLockReaderWithModuleVersion(?string $version): ComposerLockReader
    {
        $reader = $this->createStub(ComposerLockReader::class);
        $reader->method('getDecoded')->willReturn($version === null ? ['packages' => []] : [
            'packages' => [
                ['name' => 'stacknuts/magento-stackgauge', 'version' => $version],
            ],
        ]);

        return $reader;
    }

    public function testBuildAssemblesTheFullEnvelope(): void
    {
        $reporterPool = $this->createStub(ReporterPool::class);
        $reporterPool->method('collect')->willReturn([
            'core' => ['schema_version' => '1.0', 'edition' => 'Community'],
        ]);

        $config = $this->createStub(Config::class);
        $config->method('getSiteId')->willReturn('site-123');

        $composerLockReader = $this->composerLockReaderWithModuleVersion('v0.1.0');

        $payload = $this->builder($reporterPool, null, $config, $composerLockReader)->build();

        $this->assertSame('full', $payload['type']);
        $this->assertSame(DeclaresCadenceInterface::CADENCE_HOURLY, $payload['cadence']);
        $this->assertSame('1.0', $payload['schema_version']);
        $this->assertSame('v0.1.0', $payload['module_version']);
        $this->assertSame(['identifier' => 'site-123'], $payload['site']);
        $this->assertSame(['core' => ['schema_version' => '1.0', 'edition' => 'Community']], $payload['reporters']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payload['generated_at']
        );
    }

    public function testModuleVersionIsNullWhenNotAvailable(): void
    {
        $reporterPool = $this->createStub(ReporterPool::class);
        $reporterPool->method('collect')->willReturn([]);

        $config = $this->createStub(Config::class);
        $config->method('getSiteId')->willReturn(null);

        $composerLockReader = $this->composerLockReaderWithModuleVersion(null);

        $payload = $this->builder($reporterPool, null, $config, $composerLockReader)->build();

        $this->assertNull($payload['module_version']);
        $this->assertNull($payload['site']['identifier']);
    }

    public function testBuildPassesTheRequestedCadenceThroughToTheReporterPool(): void
    {
        $reporterPool = $this->createMock(ReporterPool::class);
        $reporterPool->expects($this->once())
            ->method('collect')
            ->with(DeclaresCadenceInterface::CADENCE_DAILY)
            ->willReturn([]);

        $payload = $this->builder($reporterPool)->build(DeclaresCadenceInterface::CADENCE_DAILY);

        $this->assertSame(DeclaresCadenceInterface::CADENCE_DAILY, $payload['cadence']);
    }

    public function testBuildConfigSyncAssemblesTheMetricCatalogEnvelope(): void
    {
        $metric = new MetricDefinition(
            'disk.media.free_percent',
            'Disk Space: Media Free %',
            MetricDefinition::AGGREGATION_LATEST,
            MetricDefinition::OPERATOR_LT,
            10,
            15
        );

        $metricCatalogPool = $this->createStub(MetricCatalogPool::class);
        $metricCatalogPool->method('collect')->willReturn(['disk.media.free_percent' => $metric]);

        $config = $this->createStub(Config::class);
        $config->method('getSiteId')->willReturn('site-123');

        $payload = $this->builder(null, $metricCatalogPool, $config)->buildConfigSync();

        $this->assertSame('config', $payload['type']);
        $this->assertSame(['identifier' => 'site-123'], $payload['site']);
        $this->assertSame([$metric->jsonSerialize()], $payload['metrics']);
        $this->assertArrayNotHasKey('reporters', $payload);
        $this->assertArrayNotHasKey('cadence', $payload);
    }
}
