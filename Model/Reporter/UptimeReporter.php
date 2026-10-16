<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface as StoreScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use StackNuts\StackGauge\Model\StorefrontProbe;
use StackNuts\StackGauge\Model\TlsCertificateInspector;

/**
 * Documented, admin-toggleable, trackable-metric-bearing counterpart to the same
 * Model\StorefrontProbe result Model\HeartbeatSender already sends every few minutes for fast
 * detection - see that class's own docblock for why the probe needs to live in both places:
 * a trackable metric (see getTrackableMetrics()) can only ever attach to a reporter actually
 * registered with ReporterPool (Model\MetricCatalogPool only ever looks there), which
 * HeartbeatSender deliberately isn't.
 *
 * Also checks TLS certificate expiry on every distinct https host this store's own base URLs
 * resolve to (see resolveHttpsHosts()), from this server rather than the dashboard - the same
 * reasoning as the self-probe above: a dev store behind a VPN or firewall that blocks the
 * dashboard's own IP still gets a real answer. This check is deliberately not part of
 * Model\HeartbeatSender - a certificate's expiry changes on the order of weeks, not minutes, so
 * running it every few minutes forever would be wasted load for no benefit.
 *
 * Worst-case run time is long: the storefront probe (3s connect, 5s total) plus up to
 * MAX_TLS_HOSTS hosts, each with a verified and an unverified TLS attempt of up to 5s, is about
 * 55 seconds. That is accepted for an hourly reporter in its own cron process.
 */
class UptimeReporter implements ReporterInterface, DeclaresSectionInterface, MetricCatalogInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.1';
    private const METRIC_REACHABLE = 'uptime.reachable';
    private const METRIC_RESPONSE_TIME = 'uptime.response_time_ms';
    private const METRIC_TLS_DAYS_REMAINING = 'uptime.tls_days_remaining';
    private const FINDING_TLS_UNTRUSTED = 'uptime.tls_untrusted_certificate';

    /**
     * Only distinct https hosts up to this many are ever checked - see resolveHttpsHosts().
     */
    private const MAX_TLS_HOSTS = 10;

    /**
     * Below this many days remaining, a TLS certificate's row/trackable field reads critical;
     * below WARNING but not CRITICAL, it reads warning.
     */
    private const TLS_DAYS_CRITICAL = 7;
    private const TLS_DAYS_WARNING = 21;

    /**
     * @param StorefrontProbe $storefrontProbe
     * @param MaintenanceMode $maintenanceMode
     * @param TlsCertificateInspector $tlsCertificateInspector
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly StorefrontProbe $storefrontProbe,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly TlsCertificateInspector $tlsCertificateInspector,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
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
            . 'or a genuine Magento error, whether maintenance mode is on, and TLS certificate expiry '
            . 'on this store\'s own https host(s), checked from the server itself.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the result of curling this store's own homepage (see Model\StorefrontProbe for what
     * each field means) plus TLS certificate expiry on every distinct https host it's configured
     * with (see resolveHttpsHosts() and Model\TlsCertificateInspector).
     */
    public function getStatus(): array
    {
        $result = $this->storefrontProbe->probe();

        $hosts = $this->resolveHttpsHosts();
        $tlsResults = [];
        foreach ($hosts as $host) {
            $tlsResults[$host] = $this->tlsCertificateInspector->inspect($host);
        }

        $connectedDaysRemaining = array_column(
            array_filter($tlsResults, static fn (array $tls): bool => $tls['connected']),
            'days_remaining'
        );
        $minDaysRemaining = $connectedDaysRemaining === [] ? null : min($connectedDaysRemaining);
        $anyUntrusted = array_filter(
            $tlsResults,
            static fn (array $tls): bool => $tls['connected'] && !$tls['trusted']
        ) !== [];

        $generalFields = [
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
                'Response Time',
                $result['response_time_ms'],
                self::METRIC_RESPONSE_TIME,
                MetricDefinition::AGGREGATION_AVG
            ),
            // A store left in maintenance mode after a deploy is "down" to customers even though
            // the self-probe may still get a response, so it belongs next to reachability.
            'maintenance_mode' => $this->field->bool(
                'Maintenance Mode',
                $this->maintenanceMode->isOn(),
                criticalWhen: true
            ),
            'looks_blocked' => $this->field->bool('Looks Blocked', $result['looks_blocked'], criticalWhen: true),
            'looks_like_error_page' => $this->field->bool(
                'Looks Like Error Page',
                $result['looks_like_error_page'],
                criticalWhen: true
            ),
            'tls_attempted' => $this->field->bool('TLS Attempted', $hosts !== []),
        ];

        if ($minDaysRemaining !== null) {
            $generalFields['tls_days_remaining'] = $this->field->trackableNumber(
                'TLS Certificate Days Remaining (Min)',
                $minDaysRemaining,
                self::METRIC_TLS_DAYS_REMAINING,
                MetricDefinition::AGGREGATION_LATEST,
                severity: $this->tlsDaysSeverity($minDaysRemaining),
                unit: Unit::DAYS
            );
        }

        $generalFields['tls_untrusted_certificate'] = $this->field->bool(
            'TLS Untrusted Certificate',
            $anyUntrusted,
            criticalWhen: true,
            metricKey: self::FINDING_TLS_UNTRUSTED
        );

        return [
            'general' => $this->section->facts('general', 'General', $this->getDescription(), $generalFields),
            'tls' => $this->section->table(
                'tls',
                'TLS Certificates',
                'Certificate expiry and trust, per distinct https host this store is configured with, '
                    . 'checked directly from this server.',
                $this->tlsRows($tlsResults),
                keyName: 'host'
            ),
        ];
    }

    /**
     * Alertable metrics for the uptime reporter: whether the probe found the storefront
     * reachable, homepage response time, the minimum TLS days remaining across every connected
     * https host, and a finding for any connected host whose certificate failed verification.
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
                impact: 'Customers cannot reach the store.',
                severity: MetricDefinition::SEVERITY_CRITICAL
            ),
            new MetricDefinition(
                self::METRIC_RESPONSE_TIME,
                'Uptime: Homepage Response Time',
                MetricDefinition::AGGREGATION_AVG,
                MetricDefinition::OPERATOR_GT,
                2000,
                60,
                null,
                description: 'The storefront homepage took longer than the threshold to respond, on average.',
                impact: 'Slow responses hurt conversion and search ranking, and often precede an outage.',
                criticalThreshold: 5000,
                unit: Unit::MILLISECONDS
            ),
            // Window comfortably outlives this reporter's hourly cadence. Threshold 21/critical 7
            // match the same two cutoffs the "tls" table's own per-row severity uses.
            new MetricDefinition(
                self::METRIC_TLS_DAYS_REMAINING,
                'Uptime: TLS Certificate Days Remaining (Min)',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                self::TLS_DAYS_WARNING,
                180,
                null,
                description: 'A TLS certificate on this store expires in {value} days, below the limit of {threshold}.',
                impact: 'Browsers start blocking the store once a certificate expires, and payment providers\' '
                    . 'webhook/callback requests over a broken HTTPS connection start failing.',
                criticalThreshold: self::TLS_DAYS_CRITICAL,
                unit: Unit::DAYS
            ),
            // Fires on every self-signed/internal certificate too, by design - see this class's
            // own docblock and the "tls" section's "error" column. A site that's happy with a
            // self-signed cert on a dev/staging store can disable this specific rule per site.
            MetricDefinition::finding(
                self::FINDING_TLS_UNTRUSTED,
                'Uptime: TLS Untrusted Certificate',
                MetricDefinition::SEVERITY_CRITICAL,
                'At least one of this store\'s https hosts presented a certificate that failed '
                    . 'verification (e.g. self-signed, or an incomplete/expired chain).',
                'Browsers will warn visitors or block the page outright. Expected - and safe to disable '
                    . 'per site - for a dev/staging store that deliberately uses a self-signed certificate.'
            ),
        ];
    }

    /**
     * Every distinct host this store's secure base URL resolves to, across every store view,
     * capped at MAX_TLS_HOSTS - only hosts whose base URL is actually https are included, since
     * a plain-http dev site has no certificate to check at all.
     *
     * @return list<string>
     */
    private function resolveHttpsHosts(): array
    {
        $hosts = [];

        foreach ($this->storeManager->getStores(true) as $store) {
            $baseUrl = $this->secureBaseUrl($store);

            $host = $this->httpsHostFrom($baseUrl);
            if ($host !== null) {
                // String keys dedupe while preserving first-seen order.
                $hosts[$host] = true;
            }
        }

        return array_slice(array_keys($hosts), 0, self::MAX_TLS_HOSTS);
    }

    /**
     * The store's resolved secure base URL. The raw web/secure/base_url config can still hold a
     * {{secure_base_url}}-style placeholder, which has no host and would silently skip the TLS
     * check, so Store::getBaseUrl() (which resolves placeholders) is preferred; the raw value is
     * only the fallback for a store object that doesn't expose it.
     *
     * @param StoreInterface $store
     */
    private function secureBaseUrl(StoreInterface $store): string
    {
        if ($store instanceof Store) {
            $resolved = (string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return (string)$this->scopeConfig->getValue(
            'web/secure/base_url',
            StoreScopeInterface::SCOPE_STORES,
            $store->getId()
        );
    }

    /**
     * $baseUrl's host, or null if it isn't an https URL at all.
     */
    private function httpsHostFrom(string $baseUrl): ?string
    {
        $parts = parse_url($baseUrl);

        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            return null;
        }

        return $parts['host'];
    }

    /**
     * Table rows for the "tls" section, one per inspected host.
     *
     * @param array<string,array{
     *     attempted: bool,
     *     connected: bool,
     *     trusted: bool,
     *     expires_at: string,
     *     days_remaining: int,
     *     issuer: string,
     *     subject: string,
     *     error: string|null
     * }> $tlsResults host => Model\TlsCertificateInspector::inspect() result
     * @return list<\StackNuts\StackGauge\Api\Field\ArrayField>
     */
    private function tlsRows(array $tlsResults): array
    {
        $rows = [];

        foreach ($tlsResults as $host => $tls) {
            $rows[] = $this->field->array($host, [
                'host' => $this->field->varchar('Host', $host),
                'trusted' => $this->field->bool('Trusted', $tls['trusted'], criticalWhen: false),
                'expires_at' => $this->field->datetime('Expires At', $this->toDateTimeFieldValue($tls['expires_at'])),
                'days_remaining' => $this->field->number(
                    'Days Remaining',
                    $tls['days_remaining'],
                    severity: $tls['connected'] ? $this->tlsDaysSeverity($tls['days_remaining']) : null,
                    unit: Unit::DAYS
                ),
                'issuer' => $this->field->varchar('Issuer', $tls['issuer']),
                'error' => $this->field->varchar('Error', $tls['error'] ?? ''),
            ]);
        }

        return $rows;
    }

    /**
     * Converts Model\TlsCertificateInspector's ISO-8601 expires_at into Field::datetime()'s own
     * "UTC Y-m-d H:i:s" (or '' for "never") shape.
     */
    private function toDateTimeFieldValue(string $isoExpiresAt): string
    {
        if ($isoExpiresAt === '') {
            return '';
        }

        return (new DateTimeImmutable($isoExpiresAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Field::SEVERITY_* for $daysRemaining - the same critical/warning cutoffs used by both the
     * "tls" table's per-row days-remaining column and the general section's trackable summary.
     */
    private function tlsDaysSeverity(int $daysRemaining): string
    {
        if ($daysRemaining < self::TLS_DAYS_CRITICAL) {
            return Field::SEVERITY_CRITICAL;
        }

        if ($daysRemaining < self::TLS_DAYS_WARNING) {
            return Field::SEVERITY_WARNING;
        }

        return Field::SEVERITY_OK;
    }
}
