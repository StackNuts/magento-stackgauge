<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;

class VarcharFieldTest extends TestCase
{
    public function testJsonSerializeOmitsCriticalValuesAndSeverityByDefault(): void
    {
        $field = Field::varchar('Name', 'Magento_Catalog');

        $this->assertSame(['type' => 'varchar', 'label' => 'Name', 'value' => 'Magento_Catalog'], $field->jsonSerialize());
    }

    public function testJsonSerializeIncludesCriticalValuesWhenSet(): void
    {
        $field = Field::varchar('Status', 'Suspended', ['Reindex required', 'Suspended']);

        $this->assertSame(
            [
                'type' => 'varchar',
                'label' => 'Status',
                'value' => 'Suspended',
                'critical_values' => ['Reindex required', 'Suspended'],
            ],
            $field->jsonSerialize()
        );
    }

    public function testJsonSerializeIncludesSeverityWhenSet(): void
    {
        $field = Field::varchar('Schedule Status', 'idle (0 in backlog)', severity: Field::SEVERITY_OK);

        $this->assertSame(
            [
                'type' => 'varchar',
                'label' => 'Schedule Status',
                'value' => 'idle (0 in backlog)',
                'severity' => 'ok',
            ],
            $field->jsonSerialize()
        );
    }

    public function testRejectsAnUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::varchar('Schedule Status', 'idle (0 in backlog)', severity: 'terrible');
    }

    public function testRejectsBothCriticalValuesAndSeverityAtOnce(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::varchar('Status', 'Suspended', criticalValues: ['Suspended'], severity: Field::SEVERITY_CRITICAL);
    }
}
