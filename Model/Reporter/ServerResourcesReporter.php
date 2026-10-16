<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Filesystem\Driver\File;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use Throwable;

/**
 * Host CPU/memory pressure, read straight from Linux procfs (and, when present, the cgroup v2
 * controllers a container is confined to) rather than anything Magento-specific. Every reading
 * degrades gracefully: a non-Linux host, an `open_basedir` restriction, or a /proc that simply
 * isn't readable (some hardened containers hide it) all fall back to a "measurable: false" plus
 * zeros, with no trackable metric field emitted in that case - only a genuine reading can ever
 * carry a numeric field that could alert.
 *
 * Known limits, accepted: this measures the node the cron process runs on (which may not be the
 * web node on a multi-server setup) and does so while cron itself is busy collecting reports,
 * so CPU readings lean slightly high. Metrics aggregate hourly samples, so an AVG over a 60
 * minute window is effectively one sample.
 */
class ServerResourcesReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_LOAD_PER_CORE = 'server.load_per_core';
    private const METRIC_MEMORY_AVAILABLE_PERCENT = 'server.memory_available_percent';

    private const PROC_LOADAVG = '/proc/loadavg';
    private const PROC_CPUINFO = '/proc/cpuinfo';
    private const PROC_STAT = '/proc/stat';
    private const PROC_MEMINFO = '/proc/meminfo';
    private const CGROUP_CPU_MAX = '/sys/fs/cgroup/cpu.max';
    private const CGROUP_MEMORY_MAX = '/sys/fs/cgroup/memory.max';
    private const CGROUP_MEMORY_CURRENT = '/sys/fs/cgroup/memory.current';
    private const CGROUP_MEMORY_STAT = '/sys/fs/cgroup/memory.stat';

    private const LOAD_PER_CORE_WARNING_THRESHOLD = 1.5;
    private const LOAD_PER_CORE_CRITICAL_THRESHOLD = 3.0;
    private const MEMORY_AVAILABLE_WARNING_THRESHOLD = 10.0;
    private const MEMORY_AVAILABLE_CRITICAL_THRESHOLD = 5.0;

    /**
     * Default gap between the two /proc/stat samples used to compute CPU busy/iowait percent.
     * Long enough that the counters (whole jiffies) move measurably, short enough not to make
     * a cron run noticeably slower.
     */
    private const DEFAULT_CPU_SAMPLE_DELAY_MICROSECONDS = 250000;

    /**
     * @param File $filesystemDriver
     * @param Field $field
     * @param Section $section
     * @param int $cpuSampleDelayMicroseconds Gap between the two /proc/stat samples taken to
     *     compute CPU busy/iowait percent. Overridable so tests can pass 0 and avoid a real sleep.
     */
    public function __construct(
        private readonly File $filesystemDriver,
        private readonly Field $field,
        private readonly Section $section,
        private readonly int $cpuSampleDelayMicroseconds = self::DEFAULT_CPU_SAMPLE_DELAY_MICROSECONDS
    ) {
    }

    /**
     * Payload key for the server-resources reporter.
     */
    public function getName(): string
    {
        return 'server';
    }

    /**
     * Human-readable label for the server-resources reporter block.
     */
    public function getLabel(): string
    {
        return 'Server Resources';
    }

    /**
     * One-line summary of what the server-resources reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Load average, CPU core count/busy percent, and memory/swap usage from procfs, '
            . 'with cgroup v2 limits honoured inside a container.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports load/CPU and memory/swap facts - see buildLoadAndCpuFields()/buildMemoryFields() for each half.
     */
    public function getStatus(): array
    {
        $fields = array_merge($this->buildLoadAndCpuFields(), $this->buildMemoryFields());

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), $fields)];
    }

    /**
     * Alertable metrics for the server-resources reporter: sustained CPU pressure (load per
     * core) and memory running low. CPU busy/iowait percent are deliberately not here - see
     * sampleCpuPercents()'s own docblock for why a point sample isn't trustworthy enough to alert on.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_LOAD_PER_CORE,
                'Server Resources: Load Per Core',
                MetricDefinition::AGGREGATION_AVG,
                MetricDefinition::OPERATOR_GT,
                self::LOAD_PER_CORE_WARNING_THRESHOLD,
                60,
                null,
                description: 'Average load per CPU core was {value}, above {threshold}.',
                impact: 'The server is under sustained CPU pressure - requests may slow down or start queuing.',
                criticalThreshold: self::LOAD_PER_CORE_CRITICAL_THRESHOLD
            ),
            new MetricDefinition(
                self::METRIC_MEMORY_AVAILABLE_PERCENT,
                'Server Resources: Memory Available',
                MetricDefinition::AGGREGATION_AVG,
                MetricDefinition::OPERATOR_LT,
                self::MEMORY_AVAILABLE_WARNING_THRESHOLD,
                60,
                null,
                description: 'Only {value} of memory is available, below {threshold}.',
                impact: 'The server risks swapping or the OOM killer stepping in once memory runs out.',
                criticalThreshold: self::MEMORY_AVAILABLE_CRITICAL_THRESHOLD,
                unit: Unit::PERCENT
            ),
        ];
    }

    /**
     * Builds the load-average/CPU-core/CPU-busy fields, falling back to an unmeasurable reading
     * (zeros, no trackable metric) if /proc/loadavg or the core count can't be determined.
     *
     * @return array<string, \StackNuts\StackGauge\Api\Field\FieldInterface>
     */
    private function buildLoadAndCpuFields(): array
    {
        $loadAvgContents = $this->readFile(self::PROC_LOADAVG);
        $load = $loadAvgContents !== null ? $this->parseLoadAverage($loadAvgContents) : null;

        [$cores, $quotaCores] = $this->detectCpuCores();

        $measurable = $load !== null && $cores > 0;

        $load1 = $load['load1'] ?? 0.0;
        $load5 = $load['load5'] ?? 0.0;
        $load15 = $load['load15'] ?? 0.0;

        $loadPerCore = $measurable ? round($load5 / $cores, 2) : 0.0;

        $severity = $measurable ? $this->loadPerCoreSeverity($loadPerCore) : null;

        $loadPerCoreField = $measurable
            ? $this->field->trackableNumber(
                'Load Per Core',
                $loadPerCore,
                self::METRIC_LOAD_PER_CORE,
                MetricDefinition::AGGREGATION_AVG,
                $severity
            )
            : $this->field->number('Load Per Core', 0.0);

        $cpuPercents = $this->sampleCpuPercents();

        return [
            'measurable' => $this->field->bool('Measurable', $measurable),
            'load_1' => $this->field->number('Load Average (1m)', $load1),
            'load_5' => $this->field->number('Load Average (5m)', $load5),
            'load_15' => $this->field->number('Load Average (15m)', $load15),
            'cpu_cores' => $this->field->number('CPU Cores', $cores),
            // Informational only - see detectCpuCores() for why load is never divided by it.
            'cpu_quota_cores' => $this->field->number('Container CPU Quota (cores)', $quotaCores ?? 0),
            'load_per_core' => $loadPerCoreField,
            // Two /proc/stat samples ~250ms apart, taken from inside a single cron run, are a
            // noisy point-in-time snapshot - not something worth alerting on (a brief spike
            // during the sample would false-alarm, and a real sustained spike is already
            // covered by load_per_core above). Reported as plain numbers for visibility only.
            'cpu_busy_percent' => $this->field->number('CPU Busy', $cpuPercents['busy'] ?? 0.0, unit: Unit::PERCENT),
            'cpu_iowait_percent' => $this->field->number(
                'CPU I/O Wait',
                $cpuPercents['iowait'] ?? 0.0,
                unit: Unit::PERCENT
            ),
        ];
    }

    /**
     * Builds the memory/swap fields, basing the figures on a cgroup v2 memory limit (when one
     * applies) rather than the host's own totals, and falling back to an unmeasurable reading
     * (zeros, no trackable metric) if /proc/meminfo can't be read.
     *
     * @return array<string, \StackNuts\StackGauge\Api\Field\FieldInterface>
     */
    private function buildMemoryFields(): array
    {
        $meminfoContents = $this->readFile(self::PROC_MEMINFO);
        $meminfo = $meminfoContents !== null ? $this->parseMemInfo($meminfoContents) : null;
        $measurable = $meminfo !== null;

        $totalBytes = $meminfo['mem_total'] ?? 0;
        $availableBytes = $meminfo['mem_available'] ?? 0;
        $source = 'host';

        if ($measurable) {
            $cgroupLimit = $this->readCgroupMemoryLimit();

            if ($cgroupLimit !== null) {
                $current = $this->readCgroupMemoryCurrent();

                if ($current !== null) {
                    $inactiveFile = $this->readCgroupMemoryInactiveFile() ?? 0;
                    $usedBytes = max(0, $current - $inactiveFile);
                    $totalBytes = $cgroupLimit;
                    $availableBytes = max(0, $cgroupLimit - $usedBytes);
                    $source = 'cgroup limit';
                }
            }
        }

        $availablePercent = ($measurable && $totalBytes > 0)
            ? round(($availableBytes / $totalBytes) * 100, 1)
            : 0.0;

        $severity = $measurable ? $this->memoryAvailableSeverity($availablePercent) : null;

        $availablePercentField = $measurable
            ? $this->field->trackableNumber(
                'Memory Available %',
                $availablePercent,
                self::METRIC_MEMORY_AVAILABLE_PERCENT,
                MetricDefinition::AGGREGATION_AVG,
                $severity,
                Unit::PERCENT
            )
            : $this->field->number('Memory Available %', 0.0, unit: Unit::PERCENT);

        $swapTotal = $meminfo['swap_total'] ?? 0;
        $swapFree = $meminfo['swap_free'] ?? 0;
        $swapUsed = max(0, $swapTotal - $swapFree);
        $swapUsedPercent = $swapTotal > 0 ? round(($swapUsed / $swapTotal) * 100, 1) : 0.0;

        return [
            'memory_measurable' => $this->field->bool('Memory Measurable', $measurable),
            'memory_source' => $this->field->varchar('Memory Source', $measurable ? $source : 'unknown'),
            'mem_total_bytes' => $this->field->number('Memory Total', $totalBytes, unit: Unit::BYTES),
            'mem_available_bytes' => $this->field->number('Memory Available', $availableBytes, unit: Unit::BYTES),
            'memory_available_percent' => $availablePercentField,
            'swap_total_bytes' => $this->field->number('Swap Total', $swapTotal, unit: Unit::BYTES),
            'swap_used_bytes' => $this->field->number('Swap Used', $swapUsed, unit: Unit::BYTES),
            'swap_used_percent' => $this->field->number('Swap Used %', $swapUsedPercent, unit: Unit::PERCENT),
        ];
    }

    /**
     * Severity for the load-per-core reading - matches the trackable metric's own default/critical thresholds.
     *
     * @param float $loadPerCore
     */
    private function loadPerCoreSeverity(float $loadPerCore): string
    {
        if ($loadPerCore >= self::LOAD_PER_CORE_CRITICAL_THRESHOLD) {
            return Field::SEVERITY_CRITICAL;
        }

        return $loadPerCore > self::LOAD_PER_CORE_WARNING_THRESHOLD ? Field::SEVERITY_WARNING : Field::SEVERITY_OK;
    }

    /**
     * Severity for the memory-available reading - matches the trackable metric's own default/critical thresholds.
     *
     * @param float $availablePercent
     */
    private function memoryAvailableSeverity(float $availablePercent): string
    {
        if ($availablePercent < self::MEMORY_AVAILABLE_CRITICAL_THRESHOLD) {
            return Field::SEVERITY_CRITICAL;
        }

        return $availablePercent < self::MEMORY_AVAILABLE_WARNING_THRESHOLD ? Field::SEVERITY_WARNING : Field::SEVERITY_OK;
    }

    /**
     * The /proc/cpuinfo processor count, plus a cgroup v2 CPU quota (e.g. "200000 100000" in
     * /sys/fs/cgroup/cpu.max = 2 cores) when one is set, or null. Load is always divided by
     * the cpuinfo count, never the quota: inside a container /proc/loadavg is the whole host's
     * load (unless lxcfs virtualises both files together), so dividing it by a container's
     * smaller quota would overstate load per core several times over.
     *
     * @return array{0: int, 1: int|float|null}
     */
    private function detectCpuCores(): array
    {
        $cpuinfoContents = $this->readFile(self::PROC_CPUINFO);
        $hostCores = $cpuinfoContents !== null ? $this->parseCpuCoreCount($cpuinfoContents) : 0;

        $cgroupCpuMaxContents = $this->readFile(self::CGROUP_CPU_MAX);
        $quotaCores = $cgroupCpuMaxContents !== null ? $this->parseCgroupCpuQuota($cgroupCpuMaxContents) : null;

        return [$hostCores, $quotaCores !== null && $quotaCores > 0 ? $quotaCores : null];
    }

    /**
     * Takes two /proc/stat samples a short delay apart and turns them into CPU busy/iowait
     * percent, or [] if either sample couldn't be read or parsed.
     *
     * @return array{busy?: float, iowait?: float}
     */
    private function sampleCpuPercents(): array
    {
        $first = $this->readCpuStatSample();
        if ($first === null) {
            return [];
        }

        if ($this->cpuSampleDelayMicroseconds > 0) {
            usleep($this->cpuSampleDelayMicroseconds);
        }

        $second = $this->readCpuStatSample();
        if ($second === null) {
            return [];
        }

        return $this->computeCpuPercents($first, $second) ?? [];
    }

    /**
     * One /proc/stat read+parse, or null on any failure.
     *
     * @return array{idle: float, iowait: float, total: float}|null
     */
    private function readCpuStatSample(): ?array
    {
        $contents = $this->readFile(self::PROC_STAT);

        return $contents !== null ? $this->parseCpuStatAggregate($contents) : null;
    }

    /**
     * The cgroup v2 memory limit in bytes, or null if unset ("max") or not present on this host.
     */
    private function readCgroupMemoryLimit(): ?int
    {
        $contents = $this->readFile(self::CGROUP_MEMORY_MAX);

        return $contents !== null ? $this->parseCgroupNumericValue($contents) : null;
    }

    /**
     * The cgroup v2 current memory usage in bytes, or null if unreadable.
     */
    private function readCgroupMemoryCurrent(): ?int
    {
        $contents = $this->readFile(self::CGROUP_MEMORY_CURRENT);

        return $contents !== null ? $this->parseCgroupNumericValue($contents) : null;
    }

    /**
     * The cgroup v2 `inactive_file` figure (reclaimable page cache) in bytes, or null if unreadable.
     */
    private function readCgroupMemoryInactiveFile(): ?int
    {
        $contents = $this->readFile(self::CGROUP_MEMORY_STAT);

        return $contents !== null ? $this->parseCgroupMemoryStatInactiveFile($contents) : null;
    }

    /**
     * Reads one procfs/cgroup file via the Filesystem driver, treating any missing/unreadable/
     * thrown case as "no data" rather than letting it fail the whole reporter - this is exactly
     * the condition (non-Linux host, open_basedir, a hardened container) this reporter must
     * degrade gracefully from.
     *
     * @param string $path
     */
    private function readFile(string $path): ?string
    {
        try {
            if (!$this->filesystemDriver->isExists($path) || !$this->filesystemDriver->isReadable($path)) {
                return null;
            }

            // fileGetContents() throws FileSystemException rather than returning false on
            // failure (see Magento\Framework\Filesystem\Driver\File) - the catch below covers it.
            return $this->filesystemDriver->fileGetContents($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parses /proc/loadavg's first three fields (1/5/15 minute load averages).
     *
     * @param string $contents
     * @return array{load1: float, load5: float, load15: float}|null
     */
    private function parseLoadAverage(string $contents): ?array
    {
        $parts = preg_split('/\s+/', trim($contents));

        if ($parts === false
            || count($parts) < 3
            || !is_numeric($parts[0])
            || !is_numeric($parts[1])
            || !is_numeric($parts[2])
        ) {
            return null;
        }

        return [
            'load1' => (float)$parts[0],
            'load5' => (float)$parts[1],
            'load15' => (float)$parts[2],
        ];
    }

    /**
     * Counts "processor" lines in /proc/cpuinfo - one per logical CPU core.
     *
     * @param string $contents
     */
    private function parseCpuCoreCount(string $contents): int
    {
        return (int)preg_match_all('/^processor\s*:/m', $contents);
    }

    /**
     * Parses a cgroup v2 cpu.max value ("$MAX $PERIOD", e.g. "200000 100000") into an effective
     * core count (quota/period), or null when unset ("max ...") or malformed.
     *
     * @param string $contents
     */
    private function parseCgroupCpuQuota(string $contents): ?float
    {
        $parts = preg_split('/\s+/', trim($contents));

        if ($parts === false || count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }

        $period = (float)$parts[1];

        return $period > 0.0 ? (float)$parts[0] / $period : null;
    }

    /**
     * Parses the aggregate "cpu " line of /proc/stat into the fields needed for a busy/iowait
     * percent calculation between two samples.
     *
     * @param string $contents
     * @return array{idle: float, iowait: float, total: float}|null
     */
    private function parseCpuStatAggregate(string $contents): ?array
    {
        if (!preg_match('/^cpu\s+(.+)$/m', $contents, $matches)) {
            return null;
        }

        $fields = preg_split('/\s+/', trim($matches[1]));

        if ($fields === false || $fields === []) {
            return null;
        }

        foreach ($fields as $field) {
            if (!is_numeric($field)) {
                return null;
            }
        }

        $values = array_map('floatval', $fields);

        // Only the first eight columns (user..steal) make up the total: guest and guest_nice
        // (columns 9 and 10) are already counted inside user and nice, so summing them too
        // would double-count time on a host that runs virtual machines.
        return [
            'idle' => $values[3] ?? 0.0,
            'iowait' => $values[4] ?? 0.0,
            'total' => array_sum(array_slice($values, 0, 8)),
        ];
    }

    /**
     * Turns two /proc/stat samples into busy/iowait percent over the gap between them, or null
     * if the counters didn't move forward (e.g. a counter wrap, or two identical samples).
     *
     * @param array{idle: float, iowait: float, total: float} $first
     * @param array{idle: float, iowait: float, total: float} $second
     * @return array{busy: float, iowait: float}|null
     */
    private function computeCpuPercents(array $first, array $second): ?array
    {
        $totalDelta = $second['total'] - $first['total'];

        if ($totalDelta <= 0.0) {
            return null;
        }

        $idleDelta = $second['idle'] - $first['idle'];
        $iowaitDelta = $second['iowait'] - $first['iowait'];
        $busyDelta = $totalDelta - $idleDelta - $iowaitDelta;

        return [
            'busy' => round(max(0.0, $busyDelta) / $totalDelta * 100, 1),
            'iowait' => round(max(0.0, $iowaitDelta) / $totalDelta * 100, 1),
        ];
    }

    /**
     * Parses /proc/meminfo's "Key:   12345 kB" lines into bytes, requiring at least MemTotal
     * and MemAvailable to consider the file measurable - SwapTotal/SwapFree default to 0 when
     * absent (e.g. a swapless host).
     *
     * @param string $contents
     * @return array{mem_total: int, mem_available: int, swap_total: int, swap_free: int}|null
     */
    private function parseMemInfo(string $contents): ?array
    {
        $values = [];

        if (preg_match_all('/^(\w+):\s*(\d+)\s*kB/m', $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $values[$match[1]] = (int)$match[2] * 1024;
            }
        }

        if (!isset($values['MemTotal']) || !isset($values['MemAvailable'])) {
            return null;
        }

        return [
            'mem_total' => $values['MemTotal'],
            'mem_available' => $values['MemAvailable'],
            'swap_total' => $values['SwapTotal'] ?? 0,
            'swap_free' => $values['SwapFree'] ?? 0,
        ];
    }

    /**
     * Parses a cgroup v2 single-value file (memory.max/memory.current) into bytes - "max" (no
     * limit) or anything non-numeric returns null.
     *
     * @param string $contents
     */
    private function parseCgroupNumericValue(string $contents): ?int
    {
        $value = trim($contents);

        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Parses the "inactive_file" figure out of a cgroup v2 memory.stat file - reclaimable page
     * cache that shouldn't count as genuinely "used" memory.
     *
     * @param string $contents
     */
    private function parseCgroupMemoryStatInactiveFile(string $contents): ?int
    {
        if (preg_match('/^inactive_file\s+(\d+)/m', $contents, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }
}
