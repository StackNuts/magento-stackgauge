<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use InvalidArgumentException;

/**
 * A NumberField whose value is also tracked over time for alerting - carries a stable
 * metric_key (matching a MetricDefinition declared via Api\MetricCatalogInterface) and the
 * aggregation used to combine multiple samples of it over a time window. getType() still
 * returns "number", so a consumer that doesn't care about alerting can ignore the extra
 * metric_key/aggregation keys and render it like any other number field. Implements
 * FieldInterface directly rather than extending NumberField, which is final.
 */
final class TrackableNumberField implements FieldInterface
{
    private readonly int|float $value;

    public function __construct(
        private readonly string $label,
        int|float $value,
        private readonly string $metricKey,
        private readonly string $aggregation,
        private readonly ?string $severity = null
    ) {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("Trackable number field \"{$label}\" must be finite.");
        }

        if ($metricKey === '') {
            throw new InvalidArgumentException("Trackable number field \"{$label}\" needs a non-empty metric_key.");
        }

        Field::assertValidSeverity('Trackable number', $label, $severity);

        $this->value = $value;
    }

    public function getType(): string
    {
        return Field::TYPE_NUMBER;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): int|float
    {
        return $this->value;
    }

    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    public function getAggregation(): string
    {
        return $this->aggregation;
    }

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

        return $data;
    }
}
