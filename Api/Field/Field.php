<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

/**
 * Entry point for building a reporter's status fields - construct instances via these
 * factories (injected as $this->field in a reporter), not the concrete Field classes
 * directly:
 *
 *     $this->field->bool('Cron Alive', true)
 *     $this->field->varchar('Magento Version', '2.4.9')
 *     $this->field->number('Free Disk (%)', 47.9)
 *     $this->field->array('Modules', [
 *         $this->field->array('', [
 *             'name' => $this->field->varchar('Name', 'Magento_Catalog'),
 *             'version' => $this->field->varchar('Version', '103.0.5'),
 *             'enabled' => $this->field->bool('Enabled', true),
 *         ]),
 *     ])
 *
 * Every field type validates and/or cleans its value at construction time (see each
 * class's own docblock) - a reporter that passes something invalid gets a thrown
 * InvalidArgumentException, which ReporterPool catches the same way it catches any other
 * reporter failure.
 */
class Field
{
    public const TYPE_BOOL = 'bool';
    public const TYPE_VARCHAR = 'varchar';
    public const TYPE_NUMBER = 'number';
    public const TYPE_ARRAY = 'array';
    public const TYPE_DATETIME = 'datetime';

    public const SEVERITY_OK = 'ok';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * @var list<string>
     */
    public const SEVERITIES = [self::SEVERITY_OK, self::SEVERITY_WARNING, self::SEVERITY_CRITICAL];

    /**
     * Builds a BoolField.
     *
     * @param string $label
     * @param bool $value
     * @param bool|null $criticalWhen See BoolField's own docblock - declares which value
     *     counts as "critical" for coloring; leave null for a bool with no health meaning
     *     of its own.
     * @param string|null $metricKey Makes this an alertable finding - see BoolField's docblock
     *     and MetricDefinition::finding().
     */
    public function bool(string $label, bool $value, ?bool $criticalWhen = null, ?string $metricKey = null): BoolField
    {
        return new BoolField($label, $value, $criticalWhen, $metricKey);
    }

    /**
     * $criticalValues and $severity are two mutually exclusive, optional coloring hints.
     *
     * See VarcharField's own docblock; leave both null for a plain, uncolored value.
     *
     * @param string $label
     * @param string $value
     * @param list<string>|null $criticalValues
     * @param string|null $severity
     */
    public function varchar(
        string $label,
        string $value,
        ?array $criticalValues = null,
        ?string $severity = null
    ): VarcharField {
        return new VarcharField($label, $value, $criticalValues, $severity);
    }

    /**
     * See DateTimeField's own docblock for why locale display formatting isn't this field's concern.
     *
     * @param string $label
     * @param string $value Always UTC "Y-m-d H:i:s" (or '' for "never").
     */
    public function datetime(string $label, string $value): DateTimeField
    {
        return new DateTimeField($label, $value);
    }

    /**
     * See NumberField's own docblock for what $severity means.
     *
     * @param string $label
     * @param int|float $value
     * @param string|null $severity One of Field::SEVERITY_*; leave null for a plain, uncolored value.
     * @param string|null $unit One of Api\Unit::ALL (e.g. Unit::MEGABYTES), or null for a plain count. Sent
     *     next to the value, never applied to it - keep the unit out of $label.
     */
    public function number(string $label, int|float $value, ?string $severity = null, ?string $unit = null): NumberField
    {
        return new NumberField($label, $value, $severity, $unit);
    }

    /**
     * A NumberField also tracked over time for alerting - see TrackableNumberField and Api\MetricCatalogInterface.
     *
     * @param string $label
     * @param int|float $value
     * @param string $metricKey Should match a MetricDefinition this reporter declares via getTrackableMetrics().
     * @param string $aggregation How the dashboard combines multiple samples of this metric
     *     over a time window - one of MetricDefinition::AGGREGATION_*.
     * @param string|null $severity Same optional coloring hint as Field::number()'s.
     * @param string|null $unit Same as Field::number()'s - should match the MetricDefinition's own unit.
     */
    public function trackableNumber(
        string $label,
        int|float $value,
        string $metricKey,
        string $aggregation,
        ?string $severity = null,
        ?string $unit = null
    ): TrackableNumberField {
        return new TrackableNumberField($label, $value, $metricKey, $aggregation, $severity, $unit);
    }

    /**
     * Builds an ArrayField.
     *
     * @param string $label
     * @param array<int|string,FieldInterface> $value
     */
    public function array(string $label, array $value): ArrayField
    {
        return new ArrayField($label, $value);
    }

    /**
     * Builds one ArrayField row per element of $rows, keyed by $keyFn($row).
     *
     * Each row gets a varchar column per entry in $columnLabels (row key => column label, in
     * column order). Convenience for the common case of a table whose every column is an
     * uncolored varchar pulled straight off the row - see e.g.
     * SecurityReporter::pubExecutableFileFields(). A row that needs a non-varchar column or
     * per-value coloring should keep building its ArrayField directly instead of reaching for
     * this.
     *
     * @param list<array<string,string>> $rows
     * @param callable $keyFn function(array $row): string
     * @param array<string,string> $columnLabels
     * @return list<ArrayField>
     */
    public function varcharRows(array $rows, callable $keyFn, array $columnLabels): array
    {
        return array_map(
            function (array $row) use ($keyFn, $columnLabels): ArrayField {
                $columns = [];
                foreach ($columnLabels as $rowKey => $label) {
                    $columns[$rowKey] = $this->varchar($label, $row[$rowKey]);
                }

                return $this->array($keyFn($row), $columns);
            },
            $rows
        );
    }

    /**
     * The most common severity shape across reporters: warning if $condition, else ok.
     *
     * A reporter whose condition should be SEVERITY_CRITICAL, or whose "bad" state isn't a
     * plain boolean, builds its severity directly instead.
     *
     * @param bool $condition
     * @param string $whenTrue One of Field::SEVERITY_*.
     */
    public function severityIf(bool $condition, string $whenTrue = self::SEVERITY_WARNING): string
    {
        return $condition ? $whenTrue : self::SEVERITY_OK;
    }
}
