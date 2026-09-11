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
        $section = Section::facts('general', 'General', 'Edition and version.', [
            'edition' => Field::varchar('Edition', 'Community'),
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
                'fields' => ['edition' => Field::varchar('Edition', 'Community')],
            ],
            $section->jsonSerialize()
        );
    }

    public function testFactsSectionRejectsAnArrayShapedValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array-shaped');

        Section::facts('general', 'General', '', [
            'modules' => Field::array('Modules', []),
        ]);
    }

    public function testFactsSectionRejectsANonFieldValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line intentionally malformed for the test
        Section::facts('general', 'General', '', ['edition' => 'Community']);
    }

    public function testTableSectionDerivesColumnsFromTheFirstRow(): void
    {
        $section = Section::table('modules', 'Modules', 'Every module.', [
            Field::array('Magento_Catalog', [
                'name' => Field::varchar('Name', 'Magento_Catalog'),
                'enabled' => Field::bool('Enabled', true),
            ]),
            Field::array('Magento_Cms', [
                'name' => Field::varchar('Name', 'Magento_Cms'),
                'enabled' => Field::bool('Enabled', false),
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
                ['name' => Field::varchar('Name', 'Magento_Catalog'), 'enabled' => Field::bool('Enabled', true)],
                ['name' => Field::varchar('Name', 'Magento_Cms'), 'enabled' => Field::bool('Enabled', false)],
            ],
            $json['rows']
        );
    }

    public function testTableSectionWithNoRowsHasEmptyColumnsAndRows(): void
    {
        $section = Section::table('modules', 'Modules', '', []);

        $this->assertSame([], $section->getColumns());
        $this->assertSame([], $section->jsonSerialize()['rows']);
    }

    public function testTableSectionRejectsANonArrayFieldRow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an ArrayField');

        // @phpstan-ignore-next-line intentionally malformed for the test
        Section::table('modules', 'Modules', '', [Field::varchar('Name', 'Magento_Catalog')]);
    }

    public function testTableSectionRejectsANestedArrayColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array-shaped');

        Section::table('modules', 'Modules', '', [
            Field::array('row', ['nested' => Field::array('inner', [])]),
        ]);
    }

    public function testTableSectionRejectsRowsWithMismatchedColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has columns');

        Section::table('modules', 'Modules', '', [
            Field::array('a', ['name' => Field::varchar('Name', 'a'), 'enabled' => Field::bool('Enabled', true)]),
            Field::array('b', ['name' => Field::varchar('Name', 'b')]),
        ]);
    }

    public function testTableSectionRejectsDuplicateKeyColumnValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both have "name" = "media"');

        Section::table('volumes', 'Volumes', '', [
            Field::array('media', ['name' => Field::varchar('Name', 'media'), 'free_percent' => Field::number('Free %', 10)]),
            Field::array('media-2', ['name' => Field::varchar('Name', 'media'), 'free_percent' => Field::number('Free %', 20)]),
        ]);
    }

    public function testTableSectionSkipsTheDuplicateCheckWhenNotEveryRowHasTheKeyColumn(): void
    {
        // sales.orders_hourly-style table with no obvious "name" column - must not throw.
        $section = Section::table('orders_hourly', 'Orders (hourly)', '', [
            Field::array('', ['hour' => Field::varchar('Hour', '2026-09-03 10:00'), 'count' => Field::number('Count', 3)]),
        ], keyName: 'name');

        $this->assertCount(1, $section->getRows());
    }

    public function testTableSectionRejectsTooManyRows(): void
    {
        $rows = [];
        for ($i = 0; $i < \StackNuts\StackGauge\Api\Field\ArrayField::MAX_ITEMS + 1; $i++) {
            $rows[] = Field::array((string) $i, ['name' => Field::varchar('Name', (string) $i)]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeding the max of');

        Section::table('big', 'Big', '', $rows);
    }
}
