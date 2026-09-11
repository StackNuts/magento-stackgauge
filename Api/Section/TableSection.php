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
 */
final class TableSection implements SectionInterface
{
    /**
     * @var list<ArrayField>
     */
    private readonly array $rows;

    /**
     * @var list<array{key: string, label: string}>
     */
    private readonly array $columns;

    /**
     * @param array<int, ArrayField> $rows
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $description,
        array $rows,
        private readonly string $keyName = 'name'
    ) {
        if (count($rows) > ArrayField::MAX_ITEMS) {
            throw new InvalidArgumentException(sprintf(
                'Table section "%s" has %d rows, exceeding the max of %d.',
                $key,
                count($rows),
                ArrayField::MAX_ITEMS
            ));
        }

        $rows = array_values($rows);

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

    public function getKey(): string
    {
        return $this->key;
    }

    public function getKind(): string
    {
        return Section::KIND_TABLE;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return list<ArrayField>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->getKind(),
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'columns' => $this->columns,
            'rows' => array_map(static fn (ArrayField $row): array => $row->getValue(), $this->rows),
        ];
    }
}
