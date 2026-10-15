<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;

class BoolFieldTest extends TestCase
{
    public function testJsonSerializeOmitsCriticalWhenByDefault(): void
    {
        $field = (new Field())->bool('Enabled', false);

        $this->assertSame(['type' => 'bool', 'label' => 'Enabled', 'value' => false], $field->jsonSerialize());
    }

    public function testJsonSerializeIncludesCriticalWhenWhenSet(): void
    {
        $field = (new Field())->bool('Alive', false, criticalWhen: false);

        $this->assertSame(
            ['type' => 'bool', 'label' => 'Alive', 'value' => false, 'critical_when' => false],
            $field->jsonSerialize()
        );
    }

    public function testAMetricKeyMakesItATrackedFinding(): void
    {
        $field = (new Field())->bool('Sample Data Present', true, criticalWhen: true, metricKey: 'security.sample_data_present');

        $this->assertSame(
            [
                'type' => 'bool',
                'label' => 'Sample Data Present',
                'value' => true,
                'critical_when' => true,
                'metric_key' => 'security.sample_data_present',
                'aggregation' => 'latest',
            ],
            $field->jsonSerialize()
        );
    }
}
