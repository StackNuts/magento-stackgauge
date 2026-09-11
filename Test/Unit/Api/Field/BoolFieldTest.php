<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;

class BoolFieldTest extends TestCase
{
    public function testJsonSerializeOmitsCriticalWhenByDefault(): void
    {
        $field = Field::bool('Enabled', false);

        $this->assertSame(['type' => 'bool', 'label' => 'Enabled', 'value' => false], $field->jsonSerialize());
    }

    public function testJsonSerializeIncludesCriticalWhenWhenSet(): void
    {
        $field = Field::bool('Alive', false, criticalWhen: false);

        $this->assertSame(
            ['type' => 'bool', 'label' => 'Alive', 'value' => false, 'critical_when' => false],
            $field->jsonSerialize()
        );
    }
}
