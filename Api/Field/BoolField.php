<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use StackNuts\StackGauge\Api\MetricDefinition;

/**
 * $criticalWhen declares which value (true or false) is the "something's wrong" state, e.g.
 * false for "Alive"/"Reachable", true for "Maintenance Mode"/"Sample Data Present" - a plain
 * Yes/No can't be colored consistently otherwise, since some bools are good-when-true and
 * others are good-when-false. Left null (the default) for fields with no health meaning at
 * all (e.g. a module's "Enabled" flag), which the dashboard renders as a neutral badge.
 *
 * $metricKey turns the bool into an alertable finding: it's tracked like a trackable number
 * (true = 1, false = 0) against a MetricDefinition the reporter declares - usually via
 * MetricDefinition::finding() - so the dashboard alerts while it's true with no
 * dashboard-side code per finding. Make the field true when the problem is present.
 */
class BoolField implements FieldInterface
{
    /**
     * @param string $label
     * @param bool $value
     * @param bool|null $criticalWhen See this class's own docblock for what this declares.
     * @param string|null $metricKey See this class's own docblock - makes the field an alertable finding.
     */
    public function __construct(
        private readonly string $label,
        private readonly bool $value,
        private readonly ?bool $criticalWhen = null,
        private readonly ?string $metricKey = null
    ) {
    }

    /**
     * Always Field::TYPE_BOOL.
     */
    public function getType(): string
    {
        return Field::TYPE_BOOL;
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
    public function getValue(): bool
    {
        return $this->value;
    }

    /**
     * Wire representation of this field.
     *
     * @return array{type: string, label: string, value: bool, critical_when?: bool}
     */
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];

        if ($this->criticalWhen !== null) {
            $data['critical_when'] = $this->criticalWhen;
        }

        if ($this->metricKey !== null) {
            $data['metric_key'] = $this->metricKey;
            $data['aggregation'] = MetricDefinition::AGGREGATION_LATEST;
        }

        return $data;
    }
}
