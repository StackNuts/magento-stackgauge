<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

/**
 * The units a number field or trackable metric can declare. Sent as a separate "unit" key next
 * to the value - the value itself is never converted - so the dashboard can label it and pick a
 * readable scale for display (e.g. 5368709120 bytes shown as "5 GB", 1450 ms as "1.45 s")
 * while alert thresholds, metric history and comparisons keep using the number exactly as
 * reported. A fixed set rather than free text so the dashboard knows how to scale each one;
 * a value with no unit (a plain count) simply leaves it out.
 */
class Unit
{
    public const BYTES = 'bytes';
    public const KILOBYTES = 'KB';
    public const MEGABYTES = 'MB';
    public const GIGABYTES = 'GB';
    public const MILLISECONDS = 'ms';
    public const SECONDS = 's';
    public const MINUTES = 'min';
    public const HOURS = 'h';
    public const DAYS = 'd';
    public const PERCENT = '%';

    public const ALL = [
        self::BYTES,
        self::KILOBYTES,
        self::MEGABYTES,
        self::GIGABYTES,
        self::MILLISECONDS,
        self::SECONDS,
        self::MINUTES,
        self::HOURS,
        self::DAYS,
        self::PERCENT,
    ];

    /**
     * Whether $unit is null (no unit) or one of ALL.
     *
     * @param string|null $unit
     */
    public static function isValid(?string $unit): bool
    {
        return $unit === null || in_array($unit, self::ALL, true);
    }
}
