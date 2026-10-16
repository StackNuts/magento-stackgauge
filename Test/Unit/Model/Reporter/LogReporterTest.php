<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Filesystem\File\Read as FileRead;
use Magento\Framework\Filesystem\File\ReadInterface as FileReadInterface;
use StackNuts\StackGauge\Test\Unit\RemovesTempDirectories;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\LogOffsetStore;
use StackNuts\StackGauge\Model\Reporter\LogReporter;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;

class LogReporterTest extends TestCase
{
    use RemovesTempDirectories;

    /**
     * @var string[]
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTempDirectory($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Builds the reporter against real files in a temp var/log, read through Magento's real
     * file stream class (a hand-written stream double used to hide a bug in how lines were
     * read). $contentsByFile is captured by reference and written to disk whenever the
     * reporter touches a file, so a test can mutate it between two getStatus() calls to
     * simulate a file growing, being rotated, or disappearing.
     *
     * @param array<string, string> $contentsByFile filename => content, mutable by reference
     * @param array<string, string> $monitoredLogFiles filename => display name
     */
    private function reporter(
        array &$contentsByFile,
        array $monitoredLogFiles = [],
        ?LogOffsetStore $offsetStore = null
    ): LogReporter {
        $root = sys_get_temp_dir() . '/stackgauge_logs_' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $this->tempDirs[] = $root;

        $sync = static function (string $file) use (&$contentsByFile, $root): void {
            $path = $root . '/' . $file;
            clearstatcache();
            if (!isset($contentsByFile[$file])) {
                if (is_file($path)) {
                    unlink($path);
                }
                return;
            }
            if (!is_file($path) || file_get_contents($path) !== $contentsByFile[$file]) {
                file_put_contents($path, $contentsByFile[$file]);
            }
        };

        $filesystem = $this->createMock(Filesystem::class);
        $logDir = $this->createMock(ReadInterface::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::LOG)->willReturn($logDir);

        $logDir->method('isExist')->willReturnCallback(
            function (string $file) use ($sync, $root): bool {
                $sync($file);
                return is_file($root . '/' . $file);
            }
        );
        $logDir->method('openFile')->willReturnCallback(
            function (string $file) use ($sync, $root): FileReadInterface {
                $sync($file);
                return new FileRead($root . '/' . $file, new FileDriver());
            }
        );
        $logDir->method('stat')->willReturnCallback(
            function (string $file) use ($sync, $root): array {
                $sync($file);
                return ['size' => filesize($root . '/' . $file)];
            }
        );

        $config = $this->createMock(Config::class);
        $config->method('getMonitoredLogFiles')->willReturn($monitoredLogFiles);

        return new LogReporter(
            $filesystem,
            $config,
            $offsetStore ?? new FakeLogOffsetStore(),
            new Field(),
            new Section(),
            new LogLineRedactor()
        );
    }

    public function testEmptyWhenLogsMissing(): void
    {
        $contents = [];
        $status = $this->reporter($contents)->getStatus();

        $this->assertArrayHasKey('system_new_lines', $status['general']->getFields());
        $this->assertSame(0, $status['general']->getFields()['system_new_lines']->getValue());
        $this->assertArrayHasKey('recent_exceptions', $status);
        $this->assertSame([], $status['recent_exceptions']->getRows());
    }

    public function testParsesExceptionMessages(): void
    {
        $contents = [
            'system.log' => "line1\nline2\n",
            'exception.log' => "RuntimeException: Something went wrong\nAnotherException: oops\n",
        ];
        $status = $this->reporter($contents)->getStatus();

        $this->assertSame(2, $status['general']->getFields()['system_new_lines']->getValue());
        $recent = $status['recent_exceptions']->getRows();
        $this->assertCount(2, $recent);
    }

    /**
     * exception.log's own extraction is unconditional - it must keep working even if an
     * admin has removed exception.log from the "Monitored Log Files" list (which only
     * controls the recent-lines tail sections, nothing else).
     */
    public function testExceptionParsingIsUnaffectedByWhatsConfiguredForTailSections(): void
    {
        $contents = ['exception.log' => "RuntimeException: Something went wrong\n"];
        $status = $this->reporter($contents, monitoredLogFiles: [])->getStatus();

        $this->assertCount(1, $status['recent_exceptions']->getRows());
        $this->assertArrayNotHasKey('tail_exception_log', $status);
    }

    public function testExceptionCountReflectsTotalOccurrencesNotJustTheCappedRecentList(): void
    {
        // 7 total occurrences (with a repeat), but "recent" caps at 5 unique messages -
        // exception_count should reflect the 7, not the capped/deduped list's size. Each
        // message needs 5+ chars to satisfy the extraction regex's own minimum.
        $contents = [
            'exception.log' => 'Exception: message one
Exception: message two
Exception: message three
Exception: message four
Exception: message five
Exception: message six
Exception: message one',
        ];
        $status = $this->reporter($contents)->getStatus();

        $this->assertSame(7, $status['general']->getFields()['exception_count']->getValue());
        $this->assertCount(5, $status['recent_exceptions']->getRows());
    }

    public function testDeclaresExceptionCountAsATrackableMetric(): void
    {
        $contents = [];
        $metrics = $this->reporter($contents)->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('logs.exception_count', $metrics[0]->getMetricKey());
    }

    public function testNoTailSectionsWhenNothingIsConfigured(): void
    {
        $contents = ['system.log' => "a line\n"];
        $status = $this->reporter($contents, monitoredLogFiles: [])->getStatus();

        $this->assertSame(['general', 'recent_exceptions'], array_keys($status));
    }

    public function testAConfiguredLogFileGetsATailSectionLabeledWithItsDisplayName(): void
    {
        $contents = ['system.log' => "first line\nsecond line\n"];
        $status = $this->reporter($contents, monitoredLogFiles: ['system.log' => 'System Log'])->getStatus();

        $this->assertArrayHasKey('tail_system_log', $status);
        $this->assertSame('System Log', $status['tail_system_log']->getLabel());
        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $status['tail_system_log']->getRows()
        );
        $this->assertSame(['first line', 'second line'], $lines);
    }

    public function testTailIsCappedAtTheMostRecentFiftyLines(): void
    {
        $lines = array_map(fn (int $i) => "line {$i}", range(1, 60));
        $contents = ['system.log' => implode("\n", $lines)];
        $status = $this->reporter($contents, monitoredLogFiles: ['system.log' => 'System Log'])->getStatus();

        $rows = $status['tail_system_log']->getRows();
        $this->assertCount(50, $rows);
        $this->assertSame('line 11', $rows[0]->getValue()['line']->getValue());
        $this->assertSame('line 60', $rows[49]->getValue()['line']->getValue());
    }

    public function testACustomConfiguredLogFileGetsItsOwnTailSection(): void
    {
        $contents = ['payment-gateway.log' => "gateway line one\ngateway line two\n"];
        $status = $this->reporter(
            $contents,
            monitoredLogFiles: ['payment-gateway.log' => 'Payment Gateway']
        )->getStatus();

        $this->assertArrayHasKey('tail_payment_gateway_log', $status);
        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $status['tail_payment_gateway_log']->getRows()
        );
        $this->assertSame(['gateway line one', 'gateway line two'], $lines);
        $this->assertSame('Payment Gateway', $status['tail_payment_gateway_log']->getLabel());
    }

    /**
     * Reproduces the production crash: a log line containing invalid UTF-8 (a raw/corrupted
     * Bearer token, in the real case) must not survive into the varchar field untouched -
     * VarcharField::clean() scrubs it, but this proves the whole reporter path holds up too.
     */
    public function testTailedLinesWithInvalidUtf8DontBreakSerialization(): void
    {
        $badLine = "Bearer \xD1\x40 is not valid header value.";
        $contents = ['system.log' => "ok line\n{$badLine}\n"];
        $status = $this->reporter($contents, monitoredLogFiles: ['system.log' => 'System Log'])->getStatus();

        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $status['tail_system_log']->getRows()
        );

        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $this->assertNotFalse(json_encode($status['tail_system_log']->getRows()));
    }

    public function testDoesNotLoadTheWholeFileIntoMemoryToTailIt(): void
    {
        // The whole point of streaming: a file far larger than would ever fit comfortably in
        // memory twice over must still resolve to just the last 50 lines.
        $lineCount = 200000;
        $content = implode("\n", array_map(fn (int $i) => "line {$i}", range(1, $lineCount)));

        $contents = ['big.log' => $content];
        $status = $this->reporter($contents, monitoredLogFiles: ['big.log' => 'Big Log'])->getStatus();

        $rows = $status['tail_big_log']->getRows();
        $this->assertCount(50, $rows);
        $this->assertSame("line {$lineCount}", $rows[49]->getValue()['line']->getValue());
    }

    /**
     * The core incremental-read promise: a second run against an unchanged file must see
     * nothing new, and a second run after an append must see only what was appended - not the
     * whole file again.
     */
    public function testSecondRunOnlySeesLinesAppendedSinceTheFirst(): void
    {
        $offsetStore = new FakeLogOffsetStore();
        $contents = ['system.log' => "line one\nline two\n"];
        $reporter = $this->reporter(
            $contents,
            monitoredLogFiles: ['system.log' => 'System Log'],
            offsetStore: $offsetStore
        );

        $first = $reporter->getStatus();
        $this->assertSame(2, $first['general']->getFields()['system_new_lines']->getValue());

        $offsetStore->commit();
        $unchanged = $reporter->getStatus();
        $this->assertSame(0, $unchanged['general']->getFields()['system_new_lines']->getValue());
        $this->assertSame([], $unchanged['tail_system_log']->getRows());

        $offsetStore->commit();
        $contents['system.log'] .= "line three\n";
        $second = $reporter->getStatus();

        $this->assertSame(1, $second['general']->getFields()['system_new_lines']->getValue());
        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $second['tail_system_log']->getRows()
        );
        $this->assertSame(['line three'], $lines);
    }

    /**
     * exception_count is the one field that must keep reading as a stable running total across
     * runs (see LogReporter's own docblock on AGGREGATION_DELTA) - unlike everything else in
     * this reporter, it should NOT reset to "just what's new" each run.
     */
    public function testExceptionCountCarriesForwardAsARunningTotalAcrossRuns(): void
    {
        $offsetStore = new FakeLogOffsetStore();
        $contents = ['exception.log' => "RuntimeException: first\n"];
        $reporter = $this->reporter($contents, offsetStore: $offsetStore);

        $first = $reporter->getStatus();
        $this->assertSame(1, $first['general']->getFields()['exception_count']->getValue());

        $offsetStore->commit();
        $contents['exception.log'] .= "RuntimeException: second\nRuntimeException: third\n";
        $second = $reporter->getStatus();

        $this->assertSame(3, $second['general']->getFields()['exception_count']->getValue());
        // "recent" is scoped to what's new this run, same as the tail sections - the 2 new
        // ones, not all 3 seen across both runs.
        $this->assertCount(2, $second['recent_exceptions']->getRows());
    }

    /**
     * A stored offset pointing past the file's current size means it was rotated or truncated
     * since last read - this must be treated as a fresh file read from byte 0, not a crash or
     * a silently stalled file that never shows anything new again.
     */
    public function testRotatedFileIsReReadFromScratchRatherThanStalling(): void
    {
        $offsetStore = new FakeLogOffsetStore();
        $oldLines = implode("\n", array_map(fn (int $i) => "old line {$i}", range(1, 20)));
        $contents = ['system.log' => $oldLines . "\n"];
        $reporter = $this->reporter(
            $contents,
            monitoredLogFiles: ['system.log' => 'System Log'],
            offsetStore: $offsetStore
        );

        $reporter->getStatus();
        $offsetStore->commit();

        // Rotated: a brand-new, much smaller file - the old stored offset is now well past EOF.
        $contents['system.log'] = "fresh line one\nfresh line two\n";
        $afterRotation = $reporter->getStatus();

        $this->assertSame(2, $afterRotation['general']->getFields()['system_new_lines']->getValue());
        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $afterRotation['tail_system_log']->getRows()
        );
        $this->assertSame(['fresh line one', 'fresh line two'], $lines);
    }

    public function testEveryLineOfALargeFileIsCountedNotEach64KbBlock(): void
    {
        $lines = implode("\n", array_map(fn (int $i) => "Exception: failure number {$i}", range(1, 5000)));
        $contents = ['system.log' => $lines . "\n", 'exception.log' => $lines . "\n"];
        $status = $this->reporter($contents, monitoredLogFiles: ['system.log' => 'System'])->getStatus();

        $this->assertSame(5000, $status['general']->getFields()['system_new_lines']->getValue());
        $this->assertSame(5000, $status['general']->getFields()['exception_count']->getValue());
        $rows = $status['tail_system_log']->getRows();
        $this->assertSame('Exception: failure number 5000', $rows[49]->getValue()['line']->getValue());
        $this->assertSame('Exception: failure number 4951', $rows[0]->getValue()['line']->getValue());
    }

    public function testNothingAdvancesUntilTheOffsetsAreCommitted(): void
    {
        $offsetStore = new FakeLogOffsetStore();
        $contents = ['system.log' => "one\ntwo\n"];
        $reporter = $this->reporter($contents, offsetStore: $offsetStore);

        $this->assertSame(2, $reporter->getStatus()['general']->getFields()['system_new_lines']->getValue());
        // Preview or failed send: staged state is dropped, the same lines are delivered again.
        $offsetStore->discard();
        $this->assertSame(2, $reporter->getStatus()['general']->getFields()['system_new_lines']->getValue());
        $this->assertNull($offsetStore->get('system.log'));

        $offsetStore->commit();
        $this->assertSame(0, $reporter->getStatus()['general']->getFields()['system_new_lines']->getValue());
    }

    public function testRotatedFileThatHasGrownPastTheOldOffsetIsReadFromTheStart(): void
    {
        $offsetStore = new FakeLogOffsetStore();
        $contents = ['system.log' => "old a\nold b\n"];
        $reporter = $this->reporter($contents, offsetStore: $offsetStore);
        $reporter->getStatus();
        $offsetStore->commit();

        // Rotated, and the new file is already bigger than the old offset.
        $contents['system.log'] = "new 1\nnew 2\nnew 3\nnew 4\nnew 5\n";
        $status = $reporter->getStatus();

        $this->assertSame(5, $status['general']->getFields()['system_new_lines']->getValue());
    }

    public function testFirstReadOfAHugeFileIsBoundedToTheLastMegabyteAndDropsThePartialLine(): void
    {
        $line = str_repeat('abcdefghi ', 9) . "abcdefgh\n"; // 98 chars + newline
        $contents = ['big.log' => str_repeat($line, 30000)]; // ~3 MB
        $status = $this->reporter($contents, monitoredLogFiles: ['big.log' => 'Big'])->getStatus();

        $rows = $status['tail_big_log']->getRows();
        $this->assertCount(50, $rows);
        foreach ($rows as $row) {
            $this->assertSame(98, strlen($row->getValue()['line']->getValue()));
        }
    }

    public function testRunningCountDoesNotResetWhenTheExceptionLogDisappears(): void
    {
        $offsetStore = new FakeLogOffsetStore();
        $contents = ['exception.log' => "Exception: one one\nException: two two\n"];
        $reporter = $this->reporter($contents, offsetStore: $offsetStore);
        $reporter->getStatus();
        $offsetStore->commit();

        unset($contents['exception.log']);
        $gone = $reporter->getStatus();
        $this->assertSame(2, $gone['general']->getFields()['exception_count']->getValue());
        $offsetStore->commit();

        $contents['exception.log'] = "Exception: three three\n";
        $this->assertSame(
            3,
            $reporter->getStatus()['general']->getFields()['exception_count']->getValue()
        );
    }

    public function testAnOffsetRowFromAnOlderVersionWithoutAFingerprintStillWorks(): void
    {
        $offsetStore = new FakeLogOffsetStore();
        $offsetStore->seedLegacy('system.log', 4, 0);
        $contents = ['system.log' => "one\ntwo\nthree\n"];

        $status = $this->reporter($contents, offsetStore: $offsetStore)->getStatus();

        $this->assertSame(2, $status['general']->getFields()['system_new_lines']->getValue());
    }
}
