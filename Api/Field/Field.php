<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

/**
 * Entry point for building a reporter's status fields - construct instances via these
 * factories, not the concrete Field classes directly:
 *
 *     Field::bool('Cron Alive', true)
 *     Field::varchar('Magento Version', '2.4.9')
 *     Field::number('Free Disk (%)', 47.9)
 *     Field::array('Modules', [
 *         Field::array('', [
 *             'name' => Field::varchar('Name', 'Magento_Catalog'),
 *             'version' => Field::varchar('Version', '103.0.5'),
 *             'enabled' => Field::bool('Enabled', true),
 *         ]),
 *     ])
 *
 * Every field type validates and/or cleans its value at construction time (see each
 * class's own docblock) - a reporter that passes something invalid gets a thrown
 * InvalidArgumentException, which ReporterPool catches the same way it catches any other
 * reporter failure.
 */
final class Field
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
     * $criticalWhen - see BoolField's own docblock - declares which value counts as
     * "critical" for coloring; leave null for a bool with no health meaning of its own.
     */
    public static function bool(string $label, bool $value, ?bool $criticalWhen = null): BoolField
    {
        return new BoolField($label, $value, $criticalWhen);
    }

    /**
     * $criticalValues and $severity - see VarcharField's own docblock - two mutually
     * exclusive, optional coloring hints; leave both null for a plain, uncolored value.
     *
     * @param list<string>|null $criticalValues
     */
    public static function varchar(
        string $label,
        string $value,
        ?array $criticalValues = null,
        ?string $severity = null
    ): VarcharField {
        return new VarcharField($label, $value, $criticalValues, $severity);
    }

    /**
     * $value is always UTC "Y-m-d H:i:s" (or '' for "never") - see DateTimeField's own
     * docblock for why locale display formatting isn't this field's concern.
     */
    public static function datetime(string $label, string $value): DateTimeField
    {
        return new DateTimeField($label, $value);
    }

    /**
     * $severity - see NumberField's own docblock - an optional pre-graduated ok/warning/
     * critical hint; leave null for a plain, uncolored value.
     */
    public static function number(string $label, int|float $value, ?string $severity = null): NumberField
    {
        return new NumberField($label, $value, $severity);
    }

    /**
     * A NumberField also tracked over time for alerting - see TrackableNumberField and
     * Api\MetricCatalogInterface. $metricKey should match a MetricDefinition this reporter
     * declares via getTrackableMetrics(); $aggregation is how the dashboard combines
     * multiple samples of this metric over a time window (one of
     * MetricDefinition::AGGREGATION_*). $severity is the same optional coloring hint as
     * Field::number()'s.
     */
    public static function trackableNumber(
        string $label,
        int|float $value,
        string $metricKey,
        string $aggregation,
        ?string $severity = null
    ): TrackableNumberField {
        return new TrackableNumberField($label, $value, $metricKey, $aggregation, $severity);
    }

    /**
     * @param array<int|string, FieldInterface> $value
     */
    public static function array(string $label, array $value): ArrayField
    {
        return new ArrayField($label, $value);
    }

    /**
     * Shared by every Field subclass's constructor that accepts an optional $severity.
     * $fieldKind names the field type in the exception message (e.g. "Varchar", "Number").
     */
    public static function assertValidSeverity(string $fieldKind, string $label, ?string $severity): void
    {
        if ($severity !== null && !in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException(
                "{$fieldKind} field \"{$label}\" has unknown severity \"{$severity}\"."
            );
        }
    }
}
