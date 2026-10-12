<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use StackNuts\StackGauge\Model\StorefrontProbe;

/**
 * Documented, admin-toggleable, trackable-metric-bearing counterpart to the same
 * Model\StorefrontProbe result Model\HeartbeatSender already sends every few minutes for fast
 * detection - see that class's own docblock for why the probe needs to live in both places:
 * a trackable metric (see getTrackableMetrics()) can only ever attach to a reporter actually
 * registered with ReporterPool (Model\MetricCatalogPool only ever looks there), which
 * HeartbeatSender deliberately isn't.
 */
class UptimeReporter implements ReporterInterface, DeclaresSectionInterface, MetricCatalogInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_REACHABLE = 'uptime.reachable';
    private const METRIC_RESPONSE_TIME = 'uptime.response_time_ms';

    /**
     * @param StorefrontProbe $storefrontProbe
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly StorefrontProbe $storefrontProbe,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the uptime reporter.
     */
    public function getName(): string
    {
        return 'uptime';
    }

    /**
     * Human-readable label for the uptime reporter block.
     */
    public function getLabel(): string
    {
        return 'Uptime';
    }

    /**
     * One-line summary of what the uptime reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Self-probe of this store\'s own homepage: reachable, blocked (e.g. a WAF challenge), '
            . 'or a genuine Magento error.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the result of curling this store's own homepage - see Model\StorefrontProbe for what each field means.
     */
    public function getStatus(): array
    {
        $result = $this->storefrontProbe->probe();

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'attempted' => $this->field->bool('Attempted', $result['attempted']),
            'reachable' => $this->field->trackableNumber(
                'Reachable',
                $result['reachable'] ? 1 : 0,
                self::METRIC_REACHABLE,
                MetricDefinition::AGGREGATION_MIN,
                severity: $result['reachable'] ? Field::SEVERITY_OK : Field::SEVERITY_CRITICAL
            ),
            'http_status' => $this->field->number('HTTP Status', $result['http_status']),
            'response_time_ms' => $this->field->trackableNumber(
                'Response Time (ms)',
                $result['response_time_ms'],
                self::METRIC_RESPONSE_TIME,
                MetricDefinition::AGGREGATION_AVG
            ),
            'looks_blocked' => $this->field->bool('Looks Blocked', $result['looks_blocked'], criticalWhen: true),
            'looks_like_error_page' => $this->field->bool(
                'Looks Like Error Page',
                $result['looks_like_error_page'],
                criticalWhen: true
            ),
        ])];
    }

    /**
     * Alertable metric for the uptime reporter: whether the probe found the storefront reachable.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // AGGREGATION_MIN over the window: if even one sample in the window was
            // unreachable (0), the window's minimum drops below the threshold and the alert
            // fires - exactly "was the site down at any point recently," not an average.
            new MetricDefinition(
                self::METRIC_REACHABLE,
                'Uptime: Reachable',
                MetricDefinition::AGGREGATION_MIN,
                MetricDefinition::OPERATOR_LT,
                1,
                120,
                null,
                description: 'The storefront was unreachable at the last check.',
                impact: 'Customers cannot reach the store.'
            ),
            new MetricDefinition(
                self::METRIC_RESPONSE_TIME,
                'Uptime: Homepage Response Time (ms)',
                MetricDefinition::AGGREGATION_AVG,
                MetricDefinition::OPERATOR_GT,
                2000,
                60,
                null,
                description: 'The storefront homepage took longer than the threshold to respond, on average.',
                impact: 'Slow responses hurt conversion and search ranking, and often precede an outage.'
            ),
        ];
    }
}
