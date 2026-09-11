<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Section;

use StackNuts\StackGauge\Api\Field\ArrayField;

/**
 * Entry point for building a reporter's status sections - construct instances via these
 * factories, not the concrete Section classes directly:
 *
 *     Section::facts('general', 'General', 'Edition, version, PHP version.', [
 *         'edition' => Field::varchar('Edition', 'Community'),
 *         'version' => Field::varchar('Magento Version', '2.4.9'),
 *     ])
 *
 *     Section::table('modules', 'Modules', 'Every registered module.', [
 *         Field::array('Magento_Catalog', [
 *             'name' => Field::varchar('Name', 'Magento_Catalog'),
 *             'version' => Field::varchar('Version', '103.0.5'),
 *         ]),
 *         ...
 *     ])
 *
 * Every section is constrained to exactly one canonical shape, enforced at construction time
 * (see FactsSection/TableSection's own docblocks) - a reporter that passes something that
 * doesn't fit gets a thrown InvalidArgumentException, which ReporterPool catches the same way
 * it catches any other reporter failure.
 */
final class Section
{
    public const KIND_FACTS = 'facts';
    public const KIND_TABLE = 'table';

    /**
     * A flat key -> scalar Field map, rendered as a small key/value fact table. No value may
     * be array-shaped - split those out into their own Section::table() instead.
     *
     * @param array<string, \StackNuts\StackGauge\Api\Field\FieldInterface> $fields
     */
    public static function facts(string $key, string $label, string $description, array $fields): FactsSection
    {
        return new FactsSection($key, $label, $description, $fields);
    }

    /**
     * A homogeneous list of same-shaped records, rendered as one table. Each row is an
     * ArrayField built the same way a reporter already builds one record today -
     * Field::array($rowLabel, ['col' => Field::...]) - of scalar Fields only (no nesting).
     *
     * $keyName (default "name"), if every row has a column by that name, is used only to
     * detect a reporter bug (two rows sharing the same name) at construction time - it does
     * not change the wire shape, which is always an ordered list of rows.
     *
     * @param array<int, ArrayField> $rows
     */
    public static function table(
        string $key,
        string $label,
        string $description,
        array $rows,
        string $keyName = 'name'
    ): TableSection {
        return new TableSection($key, $label, $description, $rows, $keyName);
    }
}
