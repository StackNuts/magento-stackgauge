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
 * (default operator/threshold/window, and how severe a breach is). This is a proposal, not a
 * permanent setting: the
 * dashboard seeds a new alert rule from these defaults the first time it sees the metric via
 * config-sync, and only ever reapplies them via an explicit "reset to default" action - an
 * edited threshold survives every subsequent sync. See Api\MetricCatalogInterface.
 */
class MetricDefinition implements JsonSerializable
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

    /**
     * How severe a breach of the default threshold is. A metric can also declare a stricter
     * $criticalThreshold, so the same alert opens as a warning and escalates to critical as the
     * value worsens (e.g. disk free below 10% = warning, below 5% = critical).
     */
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    private const VALID_SEVERITIES = [self::SEVERITY_WARNING, self::SEVERITY_CRITICAL];

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

    /**
     * @param string $metricKey Stable machine key, matched against Field::trackableNumber()'s metricKey.
     * @param string $label
     * @param string $aggregation One of self::AGGREGATION_*.
     * @param string $defaultOperator One of self::OPERATOR_*.
     * @param int|float $defaultThreshold
     * @param int $defaultWindowMinutes
     * @param string|null $cadence One of DeclaresCadenceInterface::CADENCE_*; stamped by MetricCatalogPool from the
     *     owning reporter, so reporter authors never set it. The dashboard uses it so the evaluation window
     *     never drops below the interval the metric is actually reported at.
     * @param string|null $description Plain-language "what happened" sentence for alert notifications. May use
     *     the placeholders {value}, {threshold} and {window}, filled in by the dashboard from the live rule.
     * @param string|null $impact Plain-language "why this matters" sentence; same placeholders as $description.
     * @param string $severity One of self::SEVERITY_* - how severe a breach of $defaultThreshold is.
     * @param int|float|null $criticalThreshold Optional second, stricter threshold (same operator) at which a
     *     warning escalates to critical. Only for a warning-severity metric, and never with OPERATOR_EQ.
     * @param string|null $unit One of Api\Unit::ALL for the metric's value and thresholds, or null for a
     *     plain count. Leave it out of $label/$description - the dashboard shows it next to the numbers.
     * @param bool $finding True for a yes/no finding - use self::finding() rather than setting this.
     */
    public function __construct(
        private readonly string $metricKey,
        private readonly string $label,
        private readonly string $aggregation,
        private readonly string $defaultOperator,
        private readonly int|float $defaultThreshold,
        private readonly int $defaultWindowMinutes,
        private readonly ?string $cadence = null,
        private readonly ?string $description = null,
        private readonly ?string $impact = null,
        private readonly string $severity = self::SEVERITY_WARNING,
        private readonly int|float|null $criticalThreshold = null,
        private readonly ?string $unit = null,
        private readonly bool $finding = false
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

        if (!in_array($severity, self::VALID_SEVERITIES, true)) {
            throw new InvalidArgumentException("Metric \"{$metricKey}\" has unknown severity \"{$severity}\".");
        }

        if ($criticalThreshold !== null) {
            $this->assertValidCriticalThreshold();
        }

        if (!Unit::isValid($unit)) {
            throw new InvalidArgumentException("Metric \"{$metricKey}\" has unknown unit \"{$unit}\".");
        }
    }

    /**
     * A critical threshold escalates a warning, so it must sit on the "worse" side of the
     * default threshold for the operator (higher for gt/gte, lower for lt/lte).
     */
    private function assertValidCriticalThreshold(): void
    {
        if ($this->severity !== self::SEVERITY_WARNING || $this->defaultOperator === self::OPERATOR_EQ) {
            throw new InvalidArgumentException(
                "Metric \"{$this->metricKey}\" can only have a critical threshold when its severity is warning "
                . 'and its operator isn\'t eq.'
            );
        }

        $stricter = in_array($this->defaultOperator, [self::OPERATOR_GT, self::OPERATOR_GTE], true)
            ? $this->criticalThreshold > $this->defaultThreshold
            : $this->criticalThreshold < $this->defaultThreshold;

        if (!$stricter) {
            throw new InvalidArgumentException(
                "Metric \"{$this->metricKey}\" critical threshold must be stricter than its default threshold."
            );
        }
    }

    /**
     * A yes/no finding: alerts while the reporter's Field::bool(..., metricKey: $metricKey)
     * is true (sent as 1). Same alert-rule pipeline as any metric - per-site enable/disable,
     * editable severity - with no dashboard code needed for a new finding.
     *
     * @param string $metricKey Must match the bool field's metricKey.
     * @param string $label
     * @param string $severity One of self::SEVERITY_*.
     * @param string|null $description Plain-language "what's wrong".
     * @param string|null $impact Plain-language "why it matters".
     * @param int $windowMinutes How far back the latest value may be - longer than the reporter's cadence.
     */
    public static function finding(
        string $metricKey,
        string $label,
        string $severity = self::SEVERITY_WARNING,
        ?string $description = null,
        ?string $impact = null,
        int $windowMinutes = 1500
    ): self {
        return new self(
            $metricKey,
            $label,
            self::AGGREGATION_LATEST,
            self::OPERATOR_EQ,
            1,
            $windowMinutes,
            null,
            $description,
            $impact,
            $severity,
            finding: true
        );
    }

    /**
     * The metric_key passed to the constructor.
     */
    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    /**
     * The label passed to the constructor.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The aggregation passed to the constructor.
     */
    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    /**
     * The default operator passed to the constructor.
     */
    public function getDefaultOperator(): string
    {
        return $this->defaultOperator;
    }

    /**
     * The default threshold passed to the constructor.
     */
    public function getDefaultThreshold(): int|float
    {
        return $this->defaultThreshold;
    }

    /**
     * The default window (in minutes) passed to the constructor.
     */
    public function getDefaultWindowMinutes(): int
    {
        return $this->defaultWindowMinutes;
    }

    /**
     * The owning reporter's cadence, or null if not yet stamped by MetricCatalogPool.
     */
    public function getCadence(): ?string
    {
        return $this->cadence;
    }

    /**
     * The description passed to the constructor, with its placeholders unexpanded.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * The impact passed to the constructor, with its placeholders unexpanded.
     */
    public function getImpact(): ?string
    {
        return $this->impact;
    }

    /**
     * How severe a breach of the default threshold is - one of self::SEVERITY_*.
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * The threshold at which a warning escalates to critical, if any.
     */
    public function getCriticalThreshold(): int|float|null
    {
        return $this->criticalThreshold;
    }

    /**
     * The unit of the metric's value and thresholds (one of Api\Unit::ALL), or null.
     */
    public function getUnit(): ?string
    {
        return $this->unit;
    }

    /**
     * Copy of this definition tagged with the owning reporter's cadence.
     */
    public function withCadence(string $cadence): self
    {
        return new self(
            $this->metricKey,
            $this->label,
            $this->aggregation,
            $this->defaultOperator,
            $this->defaultThreshold,
            $this->defaultWindowMinutes,
            $cadence,
            $this->description,
            $this->impact,
            $this->severity,
            $this->criticalThreshold,
            $this->unit,
            $this->finding
        );
    }

    /**
     * Wire representation of this metric definition.
     *
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
            'cadence' => $this->cadence,
            'description' => $this->description,
            'impact' => $this->impact,
            'default_severity' => $this->severity,
            'default_critical_threshold' => $this->criticalThreshold,
            'unit' => $this->unit,
            // "finding" = a yes/no check (see finding()): the dashboard alerts while it's true,
            // shows no "current value" and offers only severity/enable to edit.
            'kind' => $this->finding ? 'finding' : 'metric',
        ];
    }
}
