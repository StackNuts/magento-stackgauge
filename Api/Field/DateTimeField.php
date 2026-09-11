<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

/**
 * A point in time, always UTC, always "Y-m-d H:i:s" (or empty string for "never happened").
 * Locale-specific display formatting is a dashboard-side concern, not handled here.
 */
final class DateTimeField implements FieldInterface
{
    public function __construct(
        private readonly string $label,
        private readonly string $value
    ) {
    }

    public function getType(): string
    {
        return Field::TYPE_DATETIME;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];
    }
}
