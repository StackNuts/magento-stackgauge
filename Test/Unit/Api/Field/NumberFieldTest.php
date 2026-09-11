<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;

class NumberFieldTest extends TestCase
{
    public function testJsonSerializeOmitsSeverityByDefault(): void
    {
        $field = Field::number('Count', 5);

        $this->assertSame(['type' => 'number', 'label' => 'Count', 'value' => 5], $field->jsonSerialize());
    }

    public function testJsonSerializeIncludesSeverityWhenSet(): void
    {
        $field = Field::number('Free Percent', 4.2, Field::SEVERITY_CRITICAL);

        $this->assertSame(
            ['type' => 'number', 'label' => 'Free Percent', 'value' => 4.2, 'severity' => 'critical'],
            $field->jsonSerialize()
        );
    }

    public function testRejectsAnUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::number('Free Percent', 4.2, 'terrible');
    }
}
