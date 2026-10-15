<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\Unit;

class MetricDefinitionTest extends TestCase
{
    public function testJsonSerializeReturnsEveryDeclaredField(): void
    {
        $metric = new MetricDefinition(
            'disk.media.free_percent',
            'Disk Space: Media Free %',
            MetricDefinition::AGGREGATION_LATEST,
            MetricDefinition::OPERATOR_LT,
            10,
            15
        );

        $this->assertSame(
            [
                'metric_key' => 'disk.media.free_percent',
                'label' => 'Disk Space: Media Free %',
                'aggregation' => 'latest',
                'default_operator' => 'lt',
                'default_threshold' => 10,
                'default_window_minutes' => 15,
                'cadence' => null,
                'description' => null,
                'impact' => null,
                'default_severity' => 'warning',
                'default_critical_threshold' => null,
                'unit' => null,
                'kind' => 'metric',
            ],
            $metric->jsonSerialize()
        );
    }

    public function testDescriptionAndImpactSurviveWithCadenceAndSerialize(): void
    {
        $metric = (new MetricDefinition(
            'a.b',
            'A B',
            'latest',
            'gt',
            1,
            15,
            null,
            'Value {value} is over {threshold}.',
            'Customers are affected.'
        ))->withCadence('daily');

        $this->assertSame('Value {value} is over {threshold}.', $metric->getDescription());
        $this->assertSame('Customers are affected.', $metric->jsonSerialize()['impact']);
    }

    public function testWithCadenceStampsCadenceOntoTheWireRepresentation(): void
    {
        $metric = (new MetricDefinition('a.b', 'A B', 'latest', 'gt', 1, 15))->withCadence('hourly');

        $this->assertSame('hourly', $metric->getCadence());
        $this->assertSame('hourly', $metric->jsonSerialize()['cadence']);
    }

    public function testRejectsAnEmptyMetricKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('', 'Label', MetricDefinition::AGGREGATION_SUM, MetricDefinition::OPERATOR_LT, 1, 60);
    }

    public function testRejectsAnUnknownAggregation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('key', 'Label', 'median', MetricDefinition::OPERATOR_LT, 1, 60);
    }

    public function testRejectsAnUnknownDefaultOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('key', 'Label', MetricDefinition::AGGREGATION_SUM, 'between', 1, 60);
    }

    public function testRejectsANonPositiveDefaultWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('key', 'Label', MetricDefinition::AGGREGATION_SUM, MetricDefinition::OPERATOR_LT, 1, 0);
    }

    public function testSeverityCriticalThresholdAndUnitSurviveWithCadenceAndSerialize(): void
    {
        $metric = (new MetricDefinition(
            'disk.media.free_percent',
            'Disk Space: Media Free',
            MetricDefinition::AGGREGATION_LATEST,
            MetricDefinition::OPERATOR_LT,
            10,
            15,
            criticalThreshold: 5,
            unit: Unit::PERCENT
        ))->withCadence('hourly');

        $wire = $metric->jsonSerialize();

        $this->assertSame('warning', $wire['default_severity']);
        $this->assertSame(5, $wire['default_critical_threshold']);
        $this->assertSame('%', $wire['unit']);
    }

    public function testACriticalOnlyMetricCanBeDeclared(): void
    {
        $metric = new MetricDefinition('a.b', 'A B', 'latest', 'gt', 0, 15, severity: MetricDefinition::SEVERITY_CRITICAL);

        $this->assertSame('critical', $metric->jsonSerialize()['default_severity']);
    }

    public function testRejectsAnUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('a.b', 'A B', 'latest', 'gt', 0, 15, severity: 'urgent');
    }

    public function testRejectsAnUnknownUnit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('a.b', 'A B', 'latest', 'gt', 0, 15, unit: 'parsecs');
    }

    /**
     * @return array<string, array{0: string, 1: int|float, 2: int|float}>
     */
    public static function laxCriticalThresholds(): array
    {
        return [
            'gt critical not higher' => ['gt', 90, 90],
            'gte critical lower' => ['gte', 90, 80],
            'lt critical not lower' => ['lt', 10, 10],
            'lte critical higher' => ['lte', 10, 20],
        ];
    }

    #[DataProvider('laxCriticalThresholds')]
    public function testRejectsACriticalThresholdThatIsntStricter(string $operator, int $threshold, int $critical): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('a.b', 'A B', 'latest', $operator, $threshold, 15, criticalThreshold: $critical);
    }

    public function testRejectsACriticalThresholdOnACriticalMetricOrAnEqualityOperator(): void
    {
        foreach ([['gt', MetricDefinition::SEVERITY_CRITICAL], ['eq', MetricDefinition::SEVERITY_WARNING]] as [$op, $sev]) {
            try {
                new MetricDefinition('a.b', 'A B', 'latest', $op, 1, 15, severity: $sev, criticalThreshold: 2);
                $this->fail("Expected rejection for {$op}/{$sev}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFindingIsALatestEqualsOneRuleWithItsSeverity(): void
    {
        $finding = MetricDefinition::finding('security.sample_data_present', 'Sample Data', MetricDefinition::SEVERITY_CRITICAL, 'd', 'i');
        $wire = $finding->jsonSerialize();

        $this->assertSame('latest', $wire['aggregation']);
        $this->assertSame('eq', $wire['default_operator']);
        $this->assertSame(1, $wire['default_threshold']);
        $this->assertSame('critical', $wire['default_severity']);
        $this->assertSame('finding', $wire['kind']);
        $this->assertSame('finding', $finding->withCadence('daily')->jsonSerialize()['kind']);
    }
}
