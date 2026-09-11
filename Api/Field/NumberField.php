<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use InvalidArgumentException;

/**
 * $severity is one of Field::SEVERITY_* - the reporter's own pre-graduated ok/warning/
 * critical conclusion for this number, using whatever thresholds only the reporter knows
 * are meaningful. Left null (the default) for a number with no health meaning of its own,
 * which the dashboard renders as plain text.
 */
final class NumberField implements FieldInterface
{
    private readonly int|float $value;

    public function __construct(
        private readonly string $label,
        int|float $value,
        private readonly ?string $severity = null
    ) {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("Number field \"{$label}\" must be finite.");
        }

        Field::assertValidSeverity('Number', $label, $severity);

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

    public function jsonSerialize(): array
    {
        $data = ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];

        if ($this->severity !== null) {
            $data['severity'] = $this->severity;
        }

        return $data;
    }
}
