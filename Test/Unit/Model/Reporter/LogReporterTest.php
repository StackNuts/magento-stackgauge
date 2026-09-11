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
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Reporter\LogReporter;

class LogReporterTest extends TestCase
{
    /**
     * @param array<string, string> $contentsByFile
     * @param array<string, string> $monitoredLogFiles filename => display name
     */
    private function reporter(array $contentsByFile, array $monitoredLogFiles = []): LogReporter
    {
        $filesystem = $this->createMock(Filesystem::class);
        $logDir = $this->createMock(ReadInterface::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::LOG)->willReturn($logDir);

        $logDir->method('isExist')->willReturnCallback(
            fn (string $file) => isset($contentsByFile[$file])
        );
        // openFile(), not readFile() - LogReporter streams line-by-line so it never loads a
        // whole (potentially huge) log file into memory. See FakeLineStream below.
        $logDir->method('openFile')->willReturnCallback(
            fn (string $file) => new FakeLineStream($contentsByFile[$file] ?? '')
        );

        $config = $this->createMock(Config::class);
        $config->method('getMonitoredLogFiles')->willReturn($monitoredLogFiles);

        return new LogReporter($filesystem, $config);
    }

    public function testEmptyWhenLogsMissing(): void
    {
        $status = $this->reporter([])->getStatus();

        $this->assertArrayHasKey('system_new_lines', $status['general']->getFields());
        $this->assertSame(0, $status['general']->getFields()['system_new_lines']->getValue());
        $this->assertArrayHasKey('recent_exceptions', $status);
        $this->assertSame([], $status['recent_exceptions']->getRows());
    }

    public function testParsesExceptionMessages(): void
    {
        $status = $this->reporter([
            'system.log' => "line1\nline2\n",
            'exception.log' => "RuntimeException: Something went wrong\nAnotherException: oops\n",
        ])->getStatus();

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
        $status = $this->reporter(
            ['exception.log' => "RuntimeException: Something went wrong\n"],
            monitoredLogFiles: []
        )->getStatus();

        $this->assertCount(1, $status['recent_exceptions']->getRows());
        $this->assertArrayNotHasKey('tail_exception_log', $status);
    }

    public function testExceptionCountReflectsTotalOccurrencesNotJustTheCappedRecentList(): void
    {
        // 7 total occurrences (with a repeat), but "recent" caps at 5 unique messages -
        // exception_count should reflect the 7, not the capped/deduped list's size. Each
        // message needs 5+ chars to satisfy the extraction regex's own minimum.
        $status = $this->reporter([
            'exception.log' => 'Exception: message one
Exception: message two
Exception: message three
Exception: message four
Exception: message five
Exception: message six
Exception: message one',
        ])->getStatus();

        $this->assertSame(7, $status['general']->getFields()['exception_count']->getValue());
        $this->assertCount(5, $status['recent_exceptions']->getRows());
    }

    public function testDeclaresExceptionCountAsATrackableMetric(): void
    {
        $metrics = $this->reporter([])->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('logs.exception_count', $metrics[0]->getMetricKey());
    }

    public function testNoTailSectionsWhenNothingIsConfigured(): void
    {
        $status = $this->reporter(['system.log' => "a line\n"], monitoredLogFiles: [])->getStatus();

        $this->assertSame(['general', 'recent_exceptions'], array_keys($status));
    }

    public function testAConfiguredLogFileGetsATailSectionLabeledWithItsDisplayName(): void
    {
        $status = $this->reporter(
            ['system.log' => "first line\nsecond line\n"],
            monitoredLogFiles: ['system.log' => 'System Log']
        )->getStatus();

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
        $status = $this->reporter(
            ['system.log' => implode("\n", $lines)],
            monitoredLogFiles: ['system.log' => 'System Log']
        )->getStatus();

        $rows = $status['tail_system_log']->getRows();
        $this->assertCount(50, $rows);
        $this->assertSame('line 11', $rows[0]->getValue()['line']->getValue());
        $this->assertSame('line 60', $rows[49]->getValue()['line']->getValue());
    }

    public function testACustomConfiguredLogFileGetsItsOwnTailSection(): void
    {
        $status = $this->reporter(
            ['payment-gateway.log' => "gateway line one\ngateway line two\n"],
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

    public function testDoesNotLoadTheWholeFileIntoMemoryToTailIt(): void
    {
        // The whole point of streaming: a file far larger than would ever fit comfortably in
        // memory twice over must still resolve to just the last 50 lines. FakeLineStream
        // hands back one line at a time, exactly like a real file handle would.
        $lineCount = 200000;
        $content = implode("\n", array_map(fn (int $i) => "line {$i}", range(1, $lineCount)));

        $status = $this->reporter(
            ['big.log' => $content],
            monitoredLogFiles: ['big.log' => 'Big Log']
        )->getStatus();

        $rows = $status['tail_big_log']->getRows();
        $this->assertCount(50, $rows);
        $this->assertSame("line {$lineCount}", $rows[49]->getValue()['line']->getValue());
    }
}

/**
 * Minimal Filesystem\File\ReadInterface double: hands back $content one line at a time via
 * readLine()/eof(), the same contract LogReporter streams against. Methods it never calls
 * intentionally throw, so a future accidental whole-file read shows up as a test failure
 * rather than silently working against the fake.
 */
final class FakeLineStream implements \Magento\Framework\Filesystem\File\ReadInterface
{
    /** @var list<string> */
    private array $lines;

    private int $position = 0;

    public function __construct(string $content)
    {
        $this->lines = $content === '' ? [] : explode("\n", $content);

        // A trailing "\n" produces one trailing empty element from explode() - a real file
        // stream's eof() flips true right after the last real line, not one call later.
        if ($this->lines !== [] && end($this->lines) === '') {
            array_pop($this->lines);
        }
    }

    public function eof()
    {
        return $this->position >= count($this->lines);
    }

    public function readLine($length, $ending = null)
    {
        if ($this->eof()) {
            return false;
        }

        return $this->lines[$this->position++] . "\n";
    }

    public function close()
    {
        return true;
    }

    public function read($length)
    {
        throw new \LogicException('FakeLineStream does not support read() - LogReporter should only use readLine().');
    }

    public function readAll($flag = null, $context = null)
    {
        throw new \LogicException('FakeLineStream does not support readAll() - LogReporter should only use readLine().');
    }

    public function readCsv($length = 0, $delimiter = ',', $enclosure = '"', $escape = "\0")
    {
        throw new \LogicException('FakeLineStream does not support readCsv().');
    }

    public function tell()
    {
        throw new \LogicException('FakeLineStream does not support tell().');
    }

    public function seek($length, $whence = SEEK_SET)
    {
        throw new \LogicException('FakeLineStream does not support seek().');
    }

    public function stat()
    {
        throw new \LogicException('FakeLineStream does not support stat().');
    }
}
