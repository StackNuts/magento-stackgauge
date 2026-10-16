<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Field\FieldInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\ServerResourcesReporter;

/**
 * /proc and /sys/fs/cgroup aren't something Filesystem\Driver\File mocking can read for real,
 * so these tests drive a File double seeded with fixture contents per path - never the real
 * filesystem. The cpuSampleDelayMicroseconds constructor argument is set to 0 so the two
 * /proc/stat samples the reporter takes are read back-to-back with no real usleep().
 */
class ServerResourcesReporterTest extends TestCase
{
    private const PROC_LOADAVG = '/proc/loadavg';
    private const PROC_CPUINFO = '/proc/cpuinfo';
    private const PROC_STAT = '/proc/stat';
    private const PROC_MEMINFO = '/proc/meminfo';
    private const CGROUP_MEMORY_MAX = '/sys/fs/cgroup/memory.max';
    private const CGROUP_MEMORY_CURRENT = '/sys/fs/cgroup/memory.current';
    private const CGROUP_MEMORY_STAT = '/sys/fs/cgroup/memory.stat';

    /**
     * A File driver double that only knows about the given path => contents map, so these
     * tests never touch the real filesystem. A path absent from $files behaves as missing,
     * the same as an unreadable/nonexistent real file. A value that's a list of strings (used
     * for /proc/stat, read twice per getStatus() call) is returned one entry per call, holding
     * on the last entry once exhausted.
     *
     * @param array<string, string|list<string>> $files
     */
    private function fileDriverWithFiles(array $files): File
    {
        $callCounts = [];
        $filesystemDriver = $this->createMock(File::class);

        $filesystemDriver->method('isExists')->willReturnCallback(
            static fn (string $path): bool => isset($files[$path])
        );
        $filesystemDriver->method('isReadable')->willReturnCallback(
            static fn (string $path): bool => isset($files[$path])
        );
        $filesystemDriver->method('fileGetContents')->willReturnCallback(
            function (string $path) use ($files, &$callCounts) {
                if (!isset($files[$path])) {
                    return false;
                }

                $contents = $files[$path];
                if (!is_array($contents)) {
                    return $contents;
                }

                $index = $callCounts[$path] ?? 0;
                $callCounts[$path] = $index + 1;

                return $contents[$index] ?? $contents[array_key_last($contents)];
            }
        );

        return $filesystemDriver;
    }

    /**
     * @param array<string, string|list<string>> $files
     */
    private function reporter(array $files): ServerResourcesReporter
    {
        return new ServerResourcesReporter($this->fileDriverWithFiles($files), new Field(), new Section(), 0);
    }

    /**
     * @return array<string, FieldInterface>
     */
    private function generalFields(array $files): array
    {
        return $this->reporter($files)->getStatus()['general']->getFields();
    }

    public function testNormalHostReportsLoadCpuCoresAndMemory(): void
    {
        $fields = $this->generalFields([
            self::PROC_LOADAVG => "0.50 1.00 1.50 2/345 6789\n",
            self::PROC_CPUINFO => "processor\t: 0\nprocessor\t: 1\n",
            self::PROC_STAT => [
                "cpu  1000 0 1000 8000 0 0 0 0 0 0\n",
                "cpu  1100 0 1100 8300 100 0 0 0 0 0\n",
            ],
            self::PROC_MEMINFO => "MemTotal:       10000000 kB\nMemAvailable:    2000000 kB\n"
                . "SwapTotal:        500000 kB\nSwapFree:         100000 kB\n",
        ]);

        $this->assertTrue($fields['measurable']->getValue());
        $this->assertSame(0.5, $fields['load_1']->getValue());
        $this->assertSame(1.0, $fields['load_5']->getValue());
        $this->assertSame(1.5, $fields['load_15']->getValue());
        $this->assertSame(2, $fields['cpu_cores']->getValue());
        $this->assertSame(0, $fields['cpu_quota_cores']->getValue());

        // load_5 (1.00) / 2 cores = 0.5, well under the 1.5 warning threshold.
        $this->assertSame(0.5, $fields['load_per_core']->getValue());
        $this->assertSame('ok', $fields['load_per_core']->jsonSerialize()['severity']);
        $this->assertArrayHasKey('metric_key', $fields['load_per_core']->jsonSerialize());

        // Deltas between the two /proc/stat samples: total +600, idle +300, iowait +100,
        // so busy = (600 - 300 - 100) / 600 = 33.3%, iowait = 100 / 600 = 16.7%.
        $this->assertSame(33.3, $fields['cpu_busy_percent']->getValue());
        $this->assertSame(16.7, $fields['cpu_iowait_percent']->getValue());

        $this->assertTrue($fields['memory_measurable']->getValue());
        $this->assertSame('host', $fields['memory_source']->getValue());
        $this->assertSame(10000000 * 1024, $fields['mem_total_bytes']->getValue());
        $this->assertSame(2000000 * 1024, $fields['mem_available_bytes']->getValue());
        // 2,000,000 / 10,000,000 kB = 20% available, well above the 10% warning threshold.
        $this->assertSame(20.0, $fields['memory_available_percent']->getValue());
        $this->assertSame('ok', $fields['memory_available_percent']->jsonSerialize()['severity']);
        $this->assertArrayHasKey('metric_key', $fields['memory_available_percent']->jsonSerialize());

        $this->assertSame(500000 * 1024, $fields['swap_total_bytes']->getValue());
        $this->assertSame((500000 - 100000) * 1024, $fields['swap_used_bytes']->getValue());
        $this->assertSame(80.0, $fields['swap_used_percent']->getValue());
    }

    public function testCgroupCpuQuotaIsReportedButNeverDividesTheHostLoad(): void
    {
        $fields = $this->generalFields([
            self::PROC_LOADAVG => "3.00 3.00 3.00 1/1 1\n",
            self::PROC_CPUINFO => "processor\t: 0\nprocessor\t: 1\nprocessor\t: 2\nprocessor\t: 3\n",
            '/sys/fs/cgroup/cpu.max' => "200000 100000\n",
            self::PROC_MEMINFO => "MemTotal:       1000 kB\nMemAvailable:    1000 kB\n",
        ]);

        // 200000 / 100000 = a 2-core quota, shown for information only.
        $this->assertSame(4, $fields['cpu_cores']->getValue());
        $this->assertSame(2.0, $fields['cpu_quota_cores']->getValue());

        // /proc/loadavg is host-wide, so it's divided by the host's 4 cores: 3.00 / 4 = 0.75.
        $this->assertSame(0.75, $fields['load_per_core']->getValue());
        $this->assertSame('ok', $fields['load_per_core']->jsonSerialize()['severity']);
    }

    public function testCgroupMemoryLimitOverridesHostMemoryFigures(): void
    {
        $fields = $this->generalFields([
            self::PROC_LOADAVG => "0 0 0 1/1 1\n",
            self::PROC_CPUINFO => "processor\t: 0\n",
            // Host meminfo reports a much larger pool than the container is actually confined to.
            self::PROC_MEMINFO => "MemTotal:       16000000 kB\nMemAvailable:    8000000 kB\n",
            self::CGROUP_MEMORY_MAX => "1073741824\n",
            self::CGROUP_MEMORY_CURRENT => "943718400\n",
            self::CGROUP_MEMORY_STAT => "inactive_file 104857600\nactive_file 0\n",
        ]);

        $this->assertTrue($fields['memory_measurable']->getValue());
        $this->assertSame('cgroup limit', $fields['memory_source']->getValue());
        $this->assertSame(1073741824, $fields['mem_total_bytes']->getValue());

        // used = current (943718400) - inactive_file (104857600) = 838860800;
        // available = limit (1073741824) - used = 234881024.
        $this->assertSame(234881024, $fields['mem_available_bytes']->getValue());
        $this->assertSame(21.9, $fields['memory_available_percent']->getValue());
    }

    public function testUnmeasurableWhenProcIsEntirelyMissing(): void
    {
        $fields = $this->generalFields([]);

        $this->assertFalse($fields['measurable']->getValue());
        $this->assertSame(0.0, $fields['load_1']->getValue());
        $this->assertSame(0.0, $fields['load_5']->getValue());
        $this->assertSame(0.0, $fields['load_15']->getValue());
        $this->assertSame(0, $fields['cpu_cores']->getValue());
        $this->assertSame(0, $fields['cpu_quota_cores']->getValue());
        $this->assertSame(0.0, $fields['cpu_busy_percent']->getValue());
        $this->assertSame(0.0, $fields['cpu_iowait_percent']->getValue());

        // Unmeasurable must never carry a trackable metric - that would risk a false alert
        // built on a fabricated zero rather than a real reading.
        $this->assertArrayNotHasKey('metric_key', $fields['load_per_core']->jsonSerialize());
        $this->assertSame(0.0, $fields['load_per_core']->getValue());
        $this->assertArrayNotHasKey('severity', $fields['load_per_core']->jsonSerialize());

        $this->assertFalse($fields['memory_measurable']->getValue());
        $this->assertSame('unknown', $fields['memory_source']->getValue());
        $this->assertSame(0, $fields['mem_total_bytes']->getValue());
        $this->assertSame(0, $fields['mem_available_bytes']->getValue());
        $this->assertArrayNotHasKey('metric_key', $fields['memory_available_percent']->jsonSerialize());
        $this->assertSame(0.0, $fields['memory_available_percent']->getValue());
        $this->assertArrayNotHasKey('severity', $fields['memory_available_percent']->jsonSerialize());
        $this->assertSame(0, $fields['swap_total_bytes']->getValue());
        $this->assertSame(0, $fields['swap_used_bytes']->getValue());
        $this->assertSame(0.0, $fields['swap_used_percent']->getValue());
    }

    public function testUnreadableCpuStatLeavesCpuPercentsAtZeroWithoutAffectingLoad(): void
    {
        $fields = $this->generalFields([
            self::PROC_LOADAVG => "1.00 1.00 1.00 1/1 1\n",
            self::PROC_CPUINFO => "processor\t: 0\n",
            self::PROC_MEMINFO => "MemTotal:       1000 kB\nMemAvailable:    500 kB\n",
        ]);

        $this->assertTrue($fields['measurable']->getValue());
        $this->assertSame(1.0, $fields['load_per_core']->getValue());
        $this->assertSame(0.0, $fields['cpu_busy_percent']->getValue());
        $this->assertSame(0.0, $fields['cpu_iowait_percent']->getValue());
    }

    public function testTrackableMetricsDeclareTheExpectedKeysAndThresholds(): void
    {
        $metrics = $this->reporter([])->getTrackableMetrics();
        $metricsByKey = [];
        foreach ($metrics as $metric) {
            $metricsByKey[$metric->getMetricKey()] = $metric;
        }

        $this->assertArrayHasKey('server.load_per_core', $metricsByKey);
        $loadPerCore = $metricsByKey['server.load_per_core'];
        $this->assertSame(1.5, $loadPerCore->getDefaultThreshold());
        $this->assertSame(3.0, $loadPerCore->getCriticalThreshold());
        $this->assertSame(60, $loadPerCore->getDefaultWindowMinutes());

        $this->assertArrayHasKey('server.memory_available_percent', $metricsByKey);
        $memoryAvailable = $metricsByKey['server.memory_available_percent'];
        $this->assertSame(10.0, $memoryAvailable->getDefaultThreshold());
        $this->assertSame(5.0, $memoryAvailable->getCriticalThreshold());
        $this->assertSame(60, $memoryAvailable->getDefaultWindowMinutes());
    }
}
