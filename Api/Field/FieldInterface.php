<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use JsonSerializable;

/**
 * A single labeled, typed value contributed by a reporter - the building block
 * ReporterInterface::getStatus() returns instead of raw scalars/arrays. Every
 * implementation is self-describing (its own JSON carries "type" and "label"), so a
 * dashboard can render a field it's never seen before correctly without knowing the
 * reporter's schema in advance - it just switches on getType().
 *
 * Construct instances via the Field factory (Field::bool(), Field::varchar(),
 * Field::number(), Field::array()) rather than the concrete classes directly.
 */
interface FieldInterface extends JsonSerializable
{
    /**
     * One of Field::TYPE_* - stable, dashboard-facing type identifiers.
     */
    public function getType(): string;

    public function getLabel(): string;

    /**
     * The underlying value of the field. Concrete field classes provide a typed
     * return (string, bool, int|float, or array) but the interface declares mixed
     * so callers can read values in tests and other local code.
     *
     * @return mixed
     */
    public function getValue();
}
