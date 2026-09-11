<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use InvalidArgumentException;

/**
 * A short display string. Content is cleaned at construction time - strip_tags() plus
 * control-character removal, then truncated. This is not an XSS defense (escape-on-output
 * on the dashboard side is); it just means a literal "<" (e.g. a raw composer constraint
 * like "<8.4") won't survive - use a different representation (e.g. separate "operator" and
 * "version" fields) if a value genuinely needs one.
 *
 * Two mutually exclusive, optional coloring hints:
 *
 *  - $criticalValues: a finite set of exact values that count as "something's wrong" (e.g.
 *    a status field where "Suspended" is critical but "Ready" isn't). For enum-shaped values.
 *  - $severity: one of Field::SEVERITY_* directly, for a value not drawn from a small fixed
 *    set where the reporter has already graduated it into ok/warning/critical itself.
 *
 * Both left null (the default) for a varchar with no health meaning of its own.
 */
final class VarcharField implements FieldInterface
{
    public const MAX_LENGTH = 500;

    private readonly string $value;

    /**
     * @param list<string>|null $criticalValues
     */
    public function __construct(
        private readonly string $label,
        string $value,
        private readonly ?array $criticalValues = null,
        private readonly ?string $severity = null
    ) {
        if ($criticalValues !== null && $severity !== null) {
            throw new InvalidArgumentException(
                "Varchar field \"{$label}\" cannot set both \$criticalValues and \$severity."
            );
        }

        Field::assertValidSeverity('Varchar', $label, $severity);

        $this->value = self::clean($value);
    }

    public function getType(): string
    {
        return Field::TYPE_VARCHAR;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    private static function clean(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return mb_substr($value, 0, self::MAX_LENGTH);
    }

    public function jsonSerialize(): array
    {
        $data = ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];

        if ($this->criticalValues !== null) {
            $data['critical_values'] = $this->criticalValues;
        }

        if ($this->severity !== null) {
            $data['severity'] = $this->severity;
        }

        return $data;
    }
}
