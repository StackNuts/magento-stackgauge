<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;

class DateTimeFieldTest extends TestCase
{
    public function testJsonSerialize(): void
    {
        $field = Field::datetime('Updated At', '2026-09-02 17:38:11');

        $this->assertSame(
            ['type' => 'datetime', 'label' => 'Updated At', 'value' => '2026-09-02 17:38:11'],
            $field->jsonSerialize()
        );
    }

    public function testAnEmptyValueMeansNever(): void
    {
        $field = Field::datetime('Updated At', '');

        $this->assertSame('', $field->getValue());
    }
}
