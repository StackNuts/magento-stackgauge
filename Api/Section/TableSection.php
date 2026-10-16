<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Section;

use InvalidArgumentException;
use StackNuts\StackGauge\Api\Field\ArrayField;

/**
 * A homogeneous list of same-shaped records. The rules that make this "constrained to exactly
 * one canonical shape", all enforced once here at construction time rather than guessed at
 * render time:
 *
 *  - every row is an ArrayField (a record), nothing else;
 *  - every row's own children are scalar Fields only - no nesting a row's column inside
 *    another array, since a table cell can't sensibly display one;
 *  - every row has the same set of column keys as the first row.
 *
 * Columns are derived once, from the first row's own keys/labels, and shipped explicitly in
 * jsonSerialize() so the dashboard never infers them. $keyName, if every row has a column by
 * that name, is used only to catch a reporter bug (two rows sharing the same name) here at
 * the source - it plays no part in the wire shape, which is always an ordered list of rows.
 *
 * More than ArrayField::MAX_ITEMS rows are cut to the first MAX_ITEMS; the wire shape then
 * carries "truncated": true and its description notes the cut.
 */
class TableSection implements SectionInterface
{
    /**
     * @var list<ArrayField>
     */
    private readonly array $rows;

    /**
     * @var list<array{key: string, label: string}>
     */
    private readonly array $columns;

    private readonly bool $truncated;

    /**
     * @param string $key
     * @param string $label
     * @param string $description
     * @param array<int,ArrayField> $rows
     * @param string $keyName
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $description,
        array $rows,
        private readonly string $keyName = 'name'
    ) {
        $rows = array_values($rows);

        // Over the cap, keep the first MAX_ITEMS rows and flag it rather than throwing - a store
        // with hundreds of modules or rules must still report the rest of the reporter.
        $this->truncated = count($rows) > ArrayField::MAX_ITEMS;
        if ($this->truncated) {
            $rows = array_slice($rows, 0, ArrayField::MAX_ITEMS);
        }

        foreach ($rows as $index => $row) {
            if (!$row instanceof ArrayField) {
                throw new InvalidArgumentException(sprintf(
                    'Table section "%s" row %d must be an ArrayField (one record), got %s.',
                    $key,
                    $index,
                    get_debug_type($row)
                ));
            }

            foreach ($row->getValue() as $columnKey => $cell) {
                if ($cell instanceof ArrayField) {
                    throw new InvalidArgumentException(sprintf(
                        'Table section "%s" row %d column "%s" is array-shaped - a table cell '
                            . 'can\'t nest another array. Split it into its own section instead.',
                        $key,
                        $index,
                        $columnKey
                    ));
                }
            }
        }

        $firstColumnKeys = $rows === [] ? [] : array_keys($rows[0]->getValue());
        $sortedFirstColumnKeys = $firstColumnKeys;
        sort($sortedFirstColumnKeys);

        foreach ($rows as $index => $row) {
            $columnKeys = array_keys($row->getValue());
            $sortedColumnKeys = $columnKeys;
            sort($sortedColumnKeys);

            if ($sortedColumnKeys !== $sortedFirstColumnKeys) {
                throw new InvalidArgumentException(sprintf(
                    'Table section "%s" row %d has columns {%s} but row 0 has {%s}.',
                    $key,
                    $index,
                    implode(',', $columnKeys),
                    implode(',', $firstColumnKeys)
                ));
            }
        }

        $this->rows = $rows;
        $this->columns = array_map(
            fn (string $columnKey): array => ['key' => $columnKey, 'label' => $rows[0]->getValue()[$columnKey]->getLabel()],
            $firstColumnKeys
        );

        $this->assertKeyColumnIsUnique();
    }

    /**
     * Throws if two rows share the same value in the $keyName column.
     *
     * See this class's own docblock for what $keyName is for.
     */
    private function assertKeyColumnIsUnique(): void
    {
        $seen = [];

        foreach ($this->rows as $index => $row) {
            $cells = $row->getValue();

            if (!isset($cells[$this->keyName])) {
                // Not every table has an obvious name column (e.g. sales.orders_hourly) -
                // skip the check entirely rather than only checking some rows.
                return;
            }

            $value = (string) $cells[$this->keyName]->getValue();

            if (isset($seen[$value])) {
                throw new InvalidArgumentException(sprintf(
                    'Table section "%s" rows %d and %d both have "%s" = "%s" - each row should be unique.',
                    $this->key,
                    $seen[$value],
                    $index,
                    $this->keyName,
                    $value
                ));
            }

            $seen[$value] = $index;
        }
    }

    /**
     * The key passed to the constructor.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Always Section::KIND_TABLE.
     */
    public function getKind(): string
    {
        return Section::KIND_TABLE;
    }

    /**
     * The label passed to the constructor.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The description passed to the constructor.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Every row, in order.
     *
     * @return list<ArrayField>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Whether rows beyond ArrayField::MAX_ITEMS were dropped at construction.
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Column definitions derived from the first row - see this class's own docblock.
     *
     * @return list<array{key: string, label: string}>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Wire representation of this section.
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->getKind(),
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->truncated
                ? trim($this->description . sprintf(' (Truncated: showing the first %d rows.)', ArrayField::MAX_ITEMS))
                : $this->description,
            'truncated' => $this->truncated,
            'columns' => $this->columns,
            'rows' => array_map(static fn (ArrayField $row): array => $row->getValue(), $this->rows),
        ];
    }
}
