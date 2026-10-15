<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use InvalidArgumentException;
use StackNuts\StackGauge\Api\Field\Concern\ValidatesSeverityTrait;
use StackNuts\StackGauge\Api\Unit;

/**
 * A NumberField whose value is also tracked over time for alerting - carries a stable
 * metric_key (matching a MetricDefinition declared via Api\MetricCatalogInterface) and the
 * aggregation used to combine multiple samples of it over a time window. getType() still
 * returns "number", so a consumer that doesn't care about alerting can ignore the extra
 * metric_key/aggregation keys and render it like any other number field. Implements
 * FieldInterface directly rather than extending NumberField, since the two only share a
 * getType() return value and composing that is simpler than inheriting from it.
 */
class TrackableNumberField implements FieldInterface
{
    use ValidatesSeverityTrait;

    /**
     * @var int|float
     */
    private readonly int|float $value;

    /**
     * @param string $label
     * @param int|float $value
     * @param string $metricKey Must match a MetricDefinition this reporter declares via getTrackableMetrics().
     * @param string $aggregation One of MetricDefinition::AGGREGATION_*.
     * @param string|null $severity Same optional coloring hint as NumberField's.
     * @param string|null $unit Same as NumberField's - one of Api\Unit::ALL, or null.
     */
    public function __construct(
        private readonly string $label,
        int|float $value,
        private readonly string $metricKey,
        private readonly string $aggregation,
        private readonly ?string $severity = null,
        private readonly ?string $unit = null
    ) {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("Trackable number field \"{$label}\" must be finite.");
        }

        if ($metricKey === '') {
            throw new InvalidArgumentException("Trackable number field \"{$label}\" needs a non-empty metric_key.");
        }

        $this->assertValidSeverity('Trackable number', $label, $severity);

        if (!Unit::isValid($unit)) {
            throw new InvalidArgumentException("Trackable number field \"{$label}\" has unknown unit \"{$unit}\".");
        }

        $this->value = $value;
    }

    /**
     * Always Field::TYPE_NUMBER - see this class's own docblock for why.
     */
    public function getType(): string
    {
        return Field::TYPE_NUMBER;
    }

    /**
     * The label passed to the constructor.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The value passed to the constructor.
     */
    public function getValue(): int|float
    {
        return $this->value;
    }

    /**
     * The metric_key passed to the constructor.
     */
    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    /**
     * The aggregation passed to the constructor.
     */
    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    /**
     * Wire representation of this field.
     *
     * @return array{
     *     type: string,
     *     label: string,
     *     value: int|float,
     *     metric_key: string,
     *     aggregation: string,
     *     severity?: string
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->getType(),
            'label' => $this->label,
            'value' => $this->value,
            'metric_key' => $this->metricKey,
            'aggregation' => $this->aggregation,
        ];

        if ($this->severity !== null) {
            $data['severity'] = $this->severity;
        }

        if ($this->unit !== null) {
            $data['unit'] = $this->unit;
        }

        return $data;
    }
}
