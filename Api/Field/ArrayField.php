<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use InvalidArgumentException;

/**
 * A group of other fields - either a numerically-keyed list (e.g. one entry per module) or
 * a string-keyed group (e.g. "name"/"version"/"enabled" fields describing one module).
 * Nesting depth is computed bottom-up from the actual values, not threaded through
 * constructor calls, so a reporter author never has to track or pass a depth counter -
 * exceeding MAX_DEPTH or MAX_ITEMS throws, which ReporterPool treats exactly like any other
 * reporter failure (an {"error": ...} block, not a broken payload).
 */
final class ArrayField implements FieldInterface
{
    public const MAX_DEPTH = 4;
    public const MAX_ITEMS = 500;

    /**
     * @param array<int|string, FieldInterface> $value
     */
    public function __construct(
        private readonly string $label,
        private readonly array $value
    ) {
        if (count($value) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(
                "Array field \"{$label}\" has ".count($value).' items, exceeding the max of '.self::MAX_ITEMS.'.'
            );
        }

        foreach ($value as $key => $item) {
            if (! $item instanceof FieldInterface) {
                $type = get_debug_type($item);
                throw new InvalidArgumentException(
                    "Array field \"{$label}\" item \"{$key}\" must be a Field instance, got {$type}."
                );
            }
        }

        $depth = $this->depth();
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                "Array field \"{$label}\" nests {$depth} levels deep, exceeding the max of ".self::MAX_DEPTH.'.'
            );
        }
    }

    public function getType(): string
    {
        return Field::TYPE_ARRAY;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<int|string, FieldInterface>
     */
    public function getValue(): array
    {
        return $this->value;
    }

    private function depth(): int
    {
        $maxChildDepth = 0;
        foreach ($this->value as $item) {
            if ($item instanceof self) {
                $maxChildDepth = max($maxChildDepth, $item->depth());
            }
        }

        return 1 + $maxChildDepth;
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];
    }
}
