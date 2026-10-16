<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\SectionInterface;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;
use Throwable;

/**
 * Runs every registered reporter (built-in and third-party, collected via the "reporters"
 * di.xml array argument) and assembles the "reporters" block of the payload. A reporter that
 * throws, or returns something other than a Section for any key, never blocks the others -
 * its block becomes {"error": "..."} instead, so a broken third-party integration degrades
 * gracefully rather than dropping the whole report.
 */
class ReporterPool
{
    /**
     * Longest reporter error message sent to the dashboard (the full text stays in the local log).
     */
    private const ERROR_MESSAGE_MAX_LENGTH = 300;

    /**
     * Encoding used wherever a reporter's output is round-tripped: invalid UTF-8 is substituted
     * (Transport does the same on the wire) and floats keep their ".0".
     */
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    private LogLineRedactor $redactor;

    /**
     * @param ReporterInterface[] $reporters
     * @param Config $config
     * @param LoggerInterface $logger
     * @param LogLineRedactor|null $redactor
     */
    public function __construct(
        private readonly array $reporters,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        ?LogLineRedactor $redactor = null
    ) {
        $this->redactor = $redactor ?? new LogLineRedactor();
    }

    /**
     * Every registered reporter, as passed in via di.xml.
     *
     * Not filtered by cadence or the admin "Disabled Reporters" toggle.
     *
     * @return ReporterInterface[]
     */
    public function getReporters(): array
    {
        return $this->reporters;
    }

    /**
     * The "reporters" block of the payload for the given cadence tier.
     *
     * Every enabled reporter at that cadence, keyed by name, each already error-isolated via
     * collectOne().
     *
     * @param string $cadence One of Api\DeclaresCadenceInterface::CADENCE_*.
     * @param Slot|null $slot Only reporters belonging to this run; null collects the whole tier.
     * @return array<string, array<string, mixed>>
     */
    public function collect(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY, ?Slot $slot = null): array
    {
        $disabledCodes = $this->config->getDisabledReporterCodes();
        $result = [];

        foreach ($this->reporters as $reporter) {
            if (!$reporter instanceof ReporterInterface) {
                continue;
            }

            // A reporter with no cadence declaration is always hourly.
            $reporterCadence = $reporter instanceof DeclaresCadenceInterface
                ? $reporter->getCadence()
                : DeclaresCadenceInterface::CADENCE_HOURLY;

            if ($reporterCadence !== $cadence) {
                continue;
            }

            if ($slot !== null && !$slot->includes($reporter)) {
                continue;
            }

            $name = $reporter->getName();

            // Applies uniformly to every reporter, built-in or third-party - whichever name a
            // reporter declares via getName() is exactly what the admin "Disabled Reporters"
            // multiselect's options are built from (see Model\System\Config\Source\ReporterList),
            // so there's no separate built-in-vs-third-party distinction to make here at all.
            if (in_array($name, $disabledCodes, true)) {
                continue;
            }

            if (isset($result[$name])) {
                $this->logger->warning(sprintf(
                    'StackGauge: duplicate reporter name "%s" registered - the later one overwrites the earlier block.',
                    $name
                ));
            }

            $result[$name] = $this->collectOne($reporter);
        }

        return $result;
    }

    /**
     * Copies each MetricDefinition's unit onto the trackable fields carrying its metric_key,
     * so a reporter declares a metric's unit once (on the definition) rather than again on
     * every field. A field that already names its own unit keeps it.
     *
     * @param ReporterInterface $reporter
     * @param list<SectionInterface> $sections
     * @return list<mixed>
     */
    private function withMetricUnits(ReporterInterface $reporter, array $sections): array
    {
        if (!$reporter instanceof MetricCatalogInterface) {
            return $sections;
        }

        $units = [];
        foreach ($reporter->getTrackableMetrics() as $definition) {
            if ($definition->getUnit() !== null) {
                $units[$definition->getMetricKey()] = $definition->getUnit();
            }
        }

        if ($units === []) {
            return $sections;
        }

        $stamp = function (array &$field) use ($units): void {
            if (isset($field['metric_key'], $units[$field['metric_key']]) && !isset($field['unit'])) {
                $field['unit'] = $units[$field['metric_key']];
            }
        };

        $sections = json_decode(json_encode($sections, self::JSON_FLAGS), true, 512, JSON_THROW_ON_ERROR);
        foreach ($sections as &$section) {
            foreach ($section['fields'] ?? [] as $key => $field) {
                $stamp($section['fields'][$key]);
            }
            foreach ($section['rows'] ?? [] as $rowKey => $row) {
                foreach ($row as $key => $field) {
                    if (is_array($field)) {
                        $stamp($section['rows'][$rowKey][$key]);
                    }
                }
            }
        }
        unset($section);

        return $sections;
    }

    /**
     * Collects one reporter's block.
     *
     * Replaces it with an {"error": ...} marker if the reporter throws or returns something
     * invalid - see this class's own docblock.
     *
     * @param ReporterInterface $reporter
     * @return array<string, mixed>
     */
    private function collectOne(ReporterInterface $reporter): array
    {
        $startedAt = hrtime(true);

        try {
            $sections = $reporter->getStatus();

            foreach ($sections as $key => $section) {
                if (!$section instanceof SectionInterface) {
                    throw new InvalidArgumentException(sprintf(
                        'section "%s" must be a StackNuts\StackGauge\Api\Section\SectionInterface instance, got %s',
                        $key,
                        get_debug_type($section)
                    ));
                }
            }

            $result = [
                'schema_version' => $reporter->getSchemaVersion(),
                'label' => $reporter->getLabel(),
                'description' => $reporter->getDescription(),
            ];

            if ($reporter instanceof DeclaresSectionInterface) {
                $section = $reporter->getSection();

                if (in_array($section, DeclaresSectionInterface::VALID_SECTIONS, true)) {
                    $result['section'] = $section;
                } else {
                    $this->logger->warning(sprintf(
                        'StackGauge: reporter "%s" declared invalid section "%s" - omitting.',
                        $reporter->getName(),
                        $section
                    ));
                }
            }

            if ($reporter instanceof DeclaresSubjectInterface && $reporter->getSubject() !== '') {
                $result['subject'] = $reporter->getSubject();
            }

            // An ordered list, not a map - display order matters, and each section already
            // carries its own key (see SectionInterface::getKey()).
            $result['sections'] = $this->withMetricUnits($reporter, array_values($sections));

            // Prove this block encodes on its own, so a value json_encode rejects (NAN, INF,
            // too deep) turns only this reporter into an error instead of failing the send.
            json_encode($result, self::JSON_FLAGS);

            $result['duration_ms'] = $this->elapsedMs($startedAt);

            return $result;
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'StackGauge: reporter "%s" failed: %s',
                $reporter->getName(),
                $e->getMessage()
            ), ['exception' => $e]);

            // Exception messages can carry SQL, values and paths (a PDO duplicate-entry error
            // includes the customer's email), so only a redacted, truncated copy leaves the
            // store; the full message stays in the local log above.
            return [
                'error' => mb_substr(
                    $this->redactor->redact($e->getMessage()),
                    0,
                    self::ERROR_MESSAGE_MAX_LENGTH
                ),
                'duration_ms' => $this->elapsedMs($startedAt),
            ];
        }
    }

    /**
     * Whole milliseconds since an hrtime(true) reading.
     *
     * @param int|float $startedAt
     */
    private function elapsedMs(int|float $startedAt): int
    {
        return (int)round((hrtime(true) - $startedAt) / 1e6);
    }
}
