<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Declares one trackable metric a reporter exposes: a stable machine key, a human label, an
 * aggregation for combining samples over a time window, and a suggested starting alert rule
 * (default operator/threshold/window). This is a proposal, not a permanent setting: the
 * dashboard seeds a new alert rule from these defaults the first time it sees the metric via
 * config-sync, and only ever reapplies them via an explicit "reset to default" action - an
 * edited threshold survives every subsequent sync. See Api\MetricCatalogInterface.
 */
final class MetricDefinition implements JsonSerializable
{
    public const AGGREGATION_SUM = 'sum';
    public const AGGREGATION_AVG = 'avg';
    public const AGGREGATION_LATEST = 'latest';
    public const AGGREGATION_MIN = 'min';
    public const AGGREGATION_MAX = 'max';

    /**
     * max(value) - min(value) over the window - "how much did this monotonically increasing
     * counter grow" (e.g. lifetime order count), without needing a calendar-boundary concept
     * like "since midnight". For a non-decreasing series this equals latest - earliest.
     */
    public const AGGREGATION_DELTA = 'delta';

    public const OPERATOR_LT = 'lt';
    public const OPERATOR_LTE = 'lte';
    public const OPERATOR_GT = 'gt';
    public const OPERATOR_GTE = 'gte';
    public const OPERATOR_EQ = 'eq';

    private const VALID_AGGREGATIONS = [
        self::AGGREGATION_SUM,
        self::AGGREGATION_AVG,
        self::AGGREGATION_LATEST,
        self::AGGREGATION_MIN,
        self::AGGREGATION_MAX,
        self::AGGREGATION_DELTA,
    ];

    private const VALID_OPERATORS = [
        self::OPERATOR_LT,
        self::OPERATOR_LTE,
        self::OPERATOR_GT,
        self::OPERATOR_GTE,
        self::OPERATOR_EQ,
    ];

    public function __construct(
        private readonly string $metricKey,
        private readonly string $label,
        private readonly string $aggregation,
        private readonly string $defaultOperator,
        private readonly int|float $defaultThreshold,
        private readonly int $defaultWindowMinutes
    ) {
        if ($metricKey === '') {
            throw new InvalidArgumentException('Metric key must not be empty.');
        }

        if (!in_array($aggregation, self::VALID_AGGREGATIONS, true)) {
            throw new InvalidArgumentException("Metric \"{$metricKey}\" has unknown aggregation \"{$aggregation}\".");
        }

        if (!in_array($defaultOperator, self::VALID_OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Metric \"{$metricKey}\" has unknown default operator \"{$defaultOperator}\"."
            );
        }

        if ($defaultWindowMinutes <= 0) {
            throw new InvalidArgumentException(
                "Metric \"{$metricKey}\" default window must be a positive number of minutes."
            );
        }
    }

    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    public function getDefaultOperator(): string
    {
        return $this->defaultOperator;
    }

    public function getDefaultThreshold(): int|float
    {
        return $this->defaultThreshold;
    }

    public function getDefaultWindowMinutes(): int
    {
        return $this->defaultWindowMinutes;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'metric_key' => $this->metricKey,
            'label' => $this->label,
            'aggregation' => $this->aggregation,
            'default_operator' => $this->defaultOperator,
            'default_threshold' => $this->defaultThreshold,
            'default_window_minutes' => $this->defaultWindowMinutes,
        ];
    }
}
