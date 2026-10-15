<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Unit;

class NumberFieldTest extends TestCase
{
    public function testJsonSerializeOmitsSeverityByDefault(): void
    {
        $field = (new Field())->number('Count', 5);

        $this->assertSame(['type' => 'number', 'label' => 'Count', 'value' => 5], $field->jsonSerialize());
    }

    public function testJsonSerializeIncludesSeverityWhenSet(): void
    {
        $field = (new Field())->number('Free Percent', 4.2, Field::SEVERITY_CRITICAL);

        $this->assertSame(
            ['type' => 'number', 'label' => 'Free Percent', 'value' => 4.2, 'severity' => 'critical'],
            $field->jsonSerialize()
        );
    }

    public function testRejectsAnUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Field())->number('Free Percent', 4.2, 'terrible');
    }

    public function testAUnitIsSentAlongsideTheUnchangedValue(): void
    {
        $field = (new Field())->number('Used Memory', 2048, unit: Unit::MEGABYTES);

        $this->assertSame(
            ['type' => 'number', 'label' => 'Used Memory', 'value' => 2048, 'unit' => 'MB'],
            $field->jsonSerialize()
        );
    }

    public function testRejectsAnUnknownUnit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Field())->number('Size', 1, unit: 'megabytes');
    }
}
