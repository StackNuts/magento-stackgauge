<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Section;

use InvalidArgumentException;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\FieldInterface;

/**
 * A flat key -> scalar Field map. The one rule that makes this "constrained to exactly one
 * canonical shape": no value may be an ArrayField. A reporter with array-shaped data belongs
 * in a sibling Section::table() instead - see Section's own docblock for the pattern.
 */
final class FactsSection implements SectionInterface
{
    /**
     * @param array<string, FieldInterface> $fields
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $description,
        private readonly array $fields
    ) {
        foreach ($fields as $fieldKey => $field) {
            if (!$field instanceof FieldInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Facts section "%s" field "%s" must be a FieldInterface instance, got %s.',
                    $key,
                    $fieldKey,
                    get_debug_type($field)
                ));
            }

            if ($field instanceof ArrayField) {
                throw new InvalidArgumentException(sprintf(
                    'Facts section "%s" field "%s" is array-shaped - facts sections are flat scalars '
                        . 'only. Put it in its own Section::table() instead.',
                    $key,
                    $fieldKey
                ));
            }
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getKind(): string
    {
        return Section::KIND_FACTS;
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
     * @return array<string, FieldInterface>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->getKind(),
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'fields' => $this->fields,
        ];
    }
}
