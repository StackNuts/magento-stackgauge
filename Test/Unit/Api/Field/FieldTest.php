<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Field\VarcharField;

class FieldTest extends TestCase
{
    public function testBoolFieldSerializesTypeLabelAndValue(): void
    {
        $field = Field::bool('Cron Alive', true);

        $this->assertSame('bool', $field->getType());
        $this->assertSame('Cron Alive', $field->getLabel());
        $this->assertTrue($field->getValue());
        $this->assertSame(
            ['type' => 'bool', 'label' => 'Cron Alive', 'value' => true],
            $field->jsonSerialize()
        );
    }

    public function testVarcharFieldStripsHtmlTags(): void
    {
        $field = Field::varchar('Name', '<script>alert(1)</script>Magento_Catalog');

        $this->assertSame('alert(1)Magento_Catalog', $field->getValue());
    }

    public function testVarcharFieldStripsControlCharacters(): void
    {
        $field = Field::varchar('Name', "Magento\x00_Catalog\x1F");

        $this->assertSame('Magento_Catalog', $field->getValue());
    }

    public function testVarcharFieldTruncatesToMaxLength(): void
    {
        $field = Field::varchar('Name', str_repeat('a', VarcharField::MAX_LENGTH + 50));

        $this->assertSame(VarcharField::MAX_LENGTH, mb_strlen($field->getValue()));
    }

    public function testVarcharFieldLeavesOrdinaryTextUntouched(): void
    {
        $field = Field::varchar('Version', '2.4.9');

        $this->assertSame('2.4.9', $field->getValue());
    }

    public function testNumberFieldAcceptsIntAndFloat(): void
    {
        $this->assertSame(5, Field::number('Count', 5)->getValue());
        $this->assertSame(47.9, Field::number('Percent', 47.9)->getValue());
    }

    public function testNumberFieldRejectsNonFiniteFloats(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::number('Broken', NAN);
    }

    public function testNumberFieldRejectsInfinite(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::number('Broken', INF);
    }

    public function testTrackableNumberFieldSerializesMetricKeyAndAggregation(): void
    {
        $field = Field::trackableNumber('Free Percent', 47.9, 'disk.media.free_percent', 'latest');

        $this->assertSame('number', $field->getType());
        $this->assertSame(47.9, $field->getValue());
        $this->assertSame('disk.media.free_percent', $field->getMetricKey());
        $this->assertSame('latest', $field->getAggregation());
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

    public function testTrackableNumberFieldRejectsAnEmptyMetricKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::trackableNumber('Free Percent', 47.9, '', 'latest');
    }

    public function testTrackableNumberFieldRejectsNonFiniteFloats(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::trackableNumber('Broken', NAN, 'some.metric', 'latest');
    }

    public function testArrayFieldAcceptsNestedFields(): void
    {
        $field = Field::array('Module', [
            'name' => Field::varchar('Name', 'Magento_Catalog'),
            'enabled' => Field::bool('Enabled', true),
        ]);

        $this->assertSame('array', $field->getType());
        $this->assertCount(2, $field->getValue());
    }

    public function testArrayFieldRejectsNonFieldItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a Field instance');

        // @phpstan-ignore-next-line intentionally malformed for the test
        Field::array('Broken', ['name' => 'not a field']);
    }

    public function testArrayFieldRejectsTooManyItems(): void
    {
        $items = [];
        for ($i = 0; $i < ArrayField::MAX_ITEMS + 1; $i++) {
            $items[] = Field::bool((string) $i, true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeding the max of');

        Field::array('TooMany', $items);
    }

    public function testArrayFieldAllowsNestingUpToMaxDepth(): void
    {
        $field = Field::bool('leaf', true);
        for ($i = 1; $i < ArrayField::MAX_DEPTH; $i++) {
            $field = Field::array("level{$i}", ['child' => $field]);
        }

        $this->assertInstanceOf(ArrayField::class, $field);
    }

    public function testArrayFieldRejectsExcessiveNestingDepth(): void
    {
        $field = Field::bool('leaf', true);
        for ($i = 1; $i <= ArrayField::MAX_DEPTH; $i++) {
            $field = Field::array("level{$i}", ['child' => $field]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('levels deep');

        Field::array('oneMoreLevel', ['child' => $field]);
    }
}
