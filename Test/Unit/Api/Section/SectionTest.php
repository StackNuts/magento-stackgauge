<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Section;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;

class SectionTest extends TestCase
{
    public function testFactsSectionSerializesKindKeyLabelDescriptionAndFields(): void
    {
        $field = new Field();
        $section = (new Section())->facts('general', 'General', 'Edition and version.', [
            'edition' => $field->varchar('Edition', 'Community'),
        ]);

        $this->assertSame('facts', $section->getKind());
        $this->assertSame('general', $section->getKey());
        $this->assertSame('General', $section->getLabel());
        $this->assertEquals(
            [
                'kind' => 'facts',
                'key' => 'general',
                'label' => 'General',
                'description' => 'Edition and version.',
                'fields' => ['edition' => $field->varchar('Edition', 'Community')],
            ],
            $section->jsonSerialize()
        );
    }

    public function testFactsSectionRejectsAnArrayShapedValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array-shaped');

        (new Section())->facts('general', 'General', '', [
            'modules' => (new Field())->array('Modules', []),
        ]);
    }

    public function testFactsSectionRejectsANonFieldValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line intentionally malformed for the test
        (new Section())->facts('general', 'General', '', ['edition' => 'Community']);
    }

    public function testTableSectionDerivesColumnsFromTheFirstRow(): void
    {
        $field = new Field();
        $section = (new Section())->table('modules', 'Modules', 'Every module.', [
            $field->array('Magento_Catalog', [
                'name' => $field->varchar('Name', 'Magento_Catalog'),
                'enabled' => $field->bool('Enabled', true),
            ]),
            $field->array('Magento_Cms', [
                'name' => $field->varchar('Name', 'Magento_Cms'),
                'enabled' => $field->bool('Enabled', false),
            ]),
        ]);

        $this->assertSame('table', $section->getKind());
        $this->assertSame(
            [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'enabled', 'label' => 'Enabled'],
            ],
            $section->getColumns()
        );

        $json = $section->jsonSerialize();
        $this->assertSame(
            [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'enabled', 'label' => 'Enabled'],
            ],
            $json['columns']
        );
        $this->assertEquals(
            [
                ['name' => $field->varchar('Name', 'Magento_Catalog'), 'enabled' => $field->bool('Enabled', true)],
                ['name' => $field->varchar('Name', 'Magento_Cms'), 'enabled' => $field->bool('Enabled', false)],
            ],
            $json['rows']
        );
    }

    public function testTableSectionWithNoRowsHasEmptyColumnsAndRows(): void
    {
        $section = (new Section())->table('modules', 'Modules', '', []);

        $this->assertSame([], $section->getColumns());
        $this->assertSame([], $section->jsonSerialize()['rows']);
    }

    public function testTableSectionRejectsANonArrayFieldRow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an ArrayField');

        // @phpstan-ignore-next-line intentionally malformed for the test
        (new Section())->table('modules', 'Modules', '', [(new Field())->varchar('Name', 'Magento_Catalog')]);
    }

    public function testTableSectionRejectsANestedArrayColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array-shaped');

        $field = new Field();
        (new Section())->table('modules', 'Modules', '', [
            $field->array('row', ['nested' => $field->array('inner', [])]),
        ]);
    }

    public function testTableSectionRejectsRowsWithMismatchedColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has columns');

        $field = new Field();
        (new Section())->table('modules', 'Modules', '', [
            $field->array('a', ['name' => $field->varchar('Name', 'a'), 'enabled' => $field->bool('Enabled', true)]),
            $field->array('b', ['name' => $field->varchar('Name', 'b')]),
        ]);
    }

    public function testTableSectionRejectsDuplicateKeyColumnValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both have "name" = "media"');

        $field = new Field();
        (new Section())->table('volumes', 'Volumes', '', [
            $field->array('media', [
                'name' => $field->varchar('Name', 'media'),
                'free_percent' => $field->number('Free %', 10),
            ]),
            $field->array('media-2', [
                'name' => $field->varchar('Name', 'media'),
                'free_percent' => $field->number('Free %', 20),
            ]),
        ]);
    }

    public function testTableSectionSkipsTheDuplicateCheckWhenNotEveryRowHasTheKeyColumn(): void
    {
        // sales.orders_hourly-style table with no obvious "name" column - must not throw.
        $field = new Field();
        $section = (new Section())->table('orders_hourly', 'Orders (hourly)', '', [
            $field->array('', [
                'hour' => $field->varchar('Hour', '2026-09-03 10:00'),
                'count' => $field->number('Count', 3),
            ]),
        ], keyName: 'name');

        $this->assertCount(1, $section->getRows());
    }

    public function testTableSectionTruncatesAndFlagsTooManyRows(): void
    {
        $field = new Field();
        $rows = [];
        for ($i = 0; $i < \StackNuts\StackGauge\Api\Field\ArrayField::MAX_ITEMS + 1; $i++) {
            $rows[] = $field->array((string) $i, ['name' => $field->varchar('Name', (string) $i)]);
        }

        $section = (new Section())->table('big', 'Big', 'Lots.', $rows);
        $wire = $section->jsonSerialize();

        $this->assertTrue($section->isTruncated());
        $this->assertCount(\StackNuts\StackGauge\Api\Field\ArrayField::MAX_ITEMS, $section->getRows());
        $this->assertTrue($wire['truncated']);
        $this->assertStringContainsString('Truncated', $wire['description']);
        $this->assertStringStartsWith('Lots.', $wire['description']);
    }

    public function testTableSectionAtTheLimitIsNotFlagged(): void
    {
        $field = new Field();
        $rows = [];
        for ($i = 0; $i < \StackNuts\StackGauge\Api\Field\ArrayField::MAX_ITEMS; $i++) {
            $rows[] = $field->array((string) $i, ['name' => $field->varchar('Name', (string) $i)]);
        }

        $wire = (new Section())->table('big', 'Big', 'Lots.', $rows)->jsonSerialize();

        $this->assertFalse($wire['truncated']);
        $this->assertSame('Lots.', $wire['description']);
    }
}
