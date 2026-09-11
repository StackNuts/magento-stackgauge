<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricDefinition;

class TrackableNumberFieldTest extends TestCase
{
    public function testJsonSerializeOmitsSeverityByDefault(): void
    {
        $field = Field::trackableNumber('Free Percent', 47.9, 'disk.media.free_percent', MetricDefinition::AGGREGATION_LATEST);

        $this->assertSame(
            [
                'type' => 'number',
                'label' => 'Free Percent',
                'value' => 47.9,
                'metric_key' => 'disk.media.free_percent',
                'aggregation' => 'latest',
            ],
            $field->jsonSerialize()
        );
    }

    public function testJsonSerializeIncludesSeverityWhenSet(): void
    {
        $field = Field::trackableNumber(
            'Free Percent',
            4.2,
            'disk.media.free_percent',
            MetricDefinition::AGGREGATION_LATEST,
            Field::SEVERITY_CRITICAL
        );

        $this->assertSame('critical', $field->jsonSerialize()['severity']);
    }

    public function testRejectsAnUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::trackableNumber('Free Percent', 4.2, 'disk.media.free_percent', MetricDefinition::AGGREGATION_LATEST, 'terrible');
    }
}
