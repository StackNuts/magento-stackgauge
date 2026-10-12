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
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\SectionInterface;
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
     * @param ReporterInterface[] $reporters
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly array $reporters,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
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

            // An ordered list, not a map - display order matters, and each section already
            // carries its own key (see SectionInterface::getKey()).
            $result['sections'] = array_values($sections);

            return $result;
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'StackGauge: reporter "%s" failed: %s',
                $reporter->getName(),
                $e->getMessage()
            ), ['exception' => $e]);

            return ['error' => $e->getMessage()];
        }
    }
}
