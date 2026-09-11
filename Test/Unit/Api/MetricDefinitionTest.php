<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\MetricDefinition;

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
            ],
            $metric->jsonSerialize()
        );
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
}
