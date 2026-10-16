<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\File\ReadInterface as FileReadInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\ReportReporter;
use StackNuts\StackGauge\Model\Util\Clock;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;

class ReportReporterTest extends TestCase
{
    /**
     * Builds a ReportReporter against a fake var/report (and var/report/api) directory.
     *
     * @param array<string, array{content: string, mtime: int}> $webFiles var/report/<name> => data
     * @param array<string, array{content: string, mtime: int}> $apiFiles var/report/api/<name> => data
     * @param bool $reportDirExists Set false to simulate var/report never having been created.
     */
    private function reporter(
        array $webFiles,
        array $apiFiles = [],
        ?Clock $clock = null,
        bool $reportDirExists = true
    ): ReportReporter {
        $byPath = [];
        foreach ($webFiles as $name => $data) {
            $byPath['report/' . $name] = $data;
        }
        foreach ($apiFiles as $name => $data) {
            $byPath['report/api/' . $name] = $data;
        }

        $filesystem = $this->createMock(Filesystem::class);
        $varDir = $this->createMock(ReadInterface::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::VAR_DIR)->willReturn($varDir);

        $varDir->method('isExist')->willReturnCallback(
            static function (string $path) use ($reportDirExists, $apiFiles): bool {
                return match ($path) {
                    'report' => $reportDirExists,
                    'report/api' => $apiFiles !== [],
                    default => false,
                };
            }
        );

        $varDir->method('read')->willReturnCallback(
            static function (string $path) use ($webFiles, $apiFiles): array {
                if ($path === 'report') {
                    $names = array_map(static fn($n): string => 'report/' . $n, array_keys($webFiles));
                    if ($apiFiles !== []) {
                        // The "api" subdirectory itself shows up in a directory listing too -
                        // listCandidates() must skip it via isFile(), not descend into it here.
                        $names[] = 'report/api';
                    }

                    return $names;
                }

                if ($path === 'report/api') {
                    return array_map(static fn($n): string => 'report/api/' . $n, array_keys($apiFiles));
                }

                return [];
            }
        );

        $varDir->method('isFile')->willReturnCallback(
            static fn(string $path): bool => $path !== 'report/api'
        );

        $varDir->method('stat')->willReturnCallback(
            static fn(string $path): array => ['mtime' => $byPath[$path]['mtime'] ?? 0]
        );

        $varDir->method('openFile')->willReturnCallback(
            fn(string $path): FileReadInterface => $this->headStream($byPath[$path]['content'] ?? '')
        );

        return new ReportReporter(
            $filesystem,
            $clock ?? new Clock(),
            new LogLineRedactor(),
            new Field(),
            new Section()
        );
    }

    private function clockAt(string $when): Clock
    {
        $now = new DateTimeImmutable($when, new DateTimeZone('UTC'));

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn($now);

        return $clock;
    }

    /**
     * @param list<ArrayField> $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_map(static fn(ArrayField $row): string => (string)$row->getValue()['name']->getValue(), $rows);
    }

    /**
     * Minimal Filesystem\File\ReadInterface double: hands back up to $length bytes from the
     * start of $content via read(), the only method ReportReporter calls on an open report
     * file. Every other method intentionally throws, so a future accidental use (e.g.
     * switching back to a whole-file read) shows up as a test failure rather than silently
     * working against the fake.
     */
    private function headStream(string $content): FileReadInterface
    {
        return new class ($content) implements FileReadInterface {
            public function __construct(private readonly string $content)
            {
            }

            public function read($length)
            {
                return substr($this->content, 0, $length);
            }

            public function readAll($flag = null, $context = null)
            {
                throw new LogicException(
                    'FakeHeadStream does not support readAll() - ReportReporter should only read().'
                );
            }

            public function readLine($length, $ending = null)
            {
                throw new LogicException('FakeHeadStream does not support readLine().');
            }

            public function readCsv($length = 0, $delimiter = ',', $enclosure = '"', $escape = "\0")
            {
                throw new LogicException('FakeHeadStream does not support readCsv().');
            }

            public function tell()
            {
                throw new LogicException('FakeHeadStream does not support tell().');
            }

            public function seek($length, $whence = SEEK_SET)
            {
                throw new LogicException('FakeHeadStream does not support seek().');
            }

            public function eof()
            {
                throw new LogicException('FakeHeadStream does not support eof().');
            }

            public function close()
            {
                return true;
            }

            public function stat()
            {
                throw new LogicException('FakeHeadStream does not support stat().');
            }
        };
    }

    public function testEmptyWhenReportDirDoesNotExist(): void
    {
        $reporter = $this->reporter([], [], null, reportDirExists: false);
        $status = $reporter->getStatus();

        $this->assertSame(0, $status['summary']->getFields()['total']->getValue());
        $this->assertFalse($status['summary']->getFields()['has_reports']->getValue());
        $this->assertSame([], $status['recent']->getRows());
        $this->assertSame([], $status['top_messages']->getRows());
    }

    public function testEmptyWhenReportDirExistsButHasNoFiles(): void
    {
        $reporter = $this->reporter([], []);
        $status = $reporter->getStatus();

        $this->assertSame(0, $status['summary']->getFields()['total']->getValue());
        $this->assertSame([], $status['recent']->getRows());
    }

    public function testRecentTableIsSortedNewestFirstAcrossWebAndApiReports(): void
    {
        $clock = $this->clockAt('2026-10-16 12:00:00');
        $ts = $clock->now()->getTimestamp();
        $content = json_encode([0 => 'Oops', 1 => 'trace', 'url' => '/a']);

        $reporter = $this->reporter(
            [
                'old' => ['content' => $content, 'mtime' => $ts - 500],
                'newest' => ['content' => $content, 'mtime' => $ts - 10],
            ],
            ['middle' => ['content' => json_encode('Fatal Error: boom'), 'mtime' => $ts - 100]],
            $clock
        );

        $rows = $reporter->getStatus()['recent']->getRows();

        $this->assertSame(['newest', 'api/middle', 'old'], $this->names($rows));
    }

    public function testLastHourAndLast24hCounts(): void
    {
        $clock = $this->clockAt('2026-10-16 12:00:00');
        $ts = $clock->now()->getTimestamp();
        $content = (string)json_encode([0 => 'Oops', 1 => 'trace']);

        $reporter = $this->reporter(
            [
                'within_hour' => ['content' => $content, 'mtime' => $ts - 60],
                'within_day_not_hour' => ['content' => $content, 'mtime' => $ts - (5 * 3600)],
                'older_than_day' => ['content' => $content, 'mtime' => $ts - (30 * 3600)],
            ],
            [],
            $clock
        );

        $summary = $reporter->getStatus()['summary']->getFields();

        $this->assertSame(3, $summary['total']->getValue());
        $this->assertSame(1, $summary['new_last_hour']->getValue());
        $this->assertSame(2, $summary['new_last_24h']->getValue());
        $this->assertTrue($summary['has_reports']->getValue());
        $this->assertSame(1, $summary['newest_age_minutes']->getValue());
    }

    public function testMessageAndUrlPathExtractedFromARealisticWebReportFile(): void
    {
        $content = json_encode([
            0 => 'Some domain error happened',
            1 => "#0 SomeClass->method()\n#1 {main}",
            'url' => '/catalog/product/view/id/42?utm_source=secret&token=abc123',
            'script_name' => '/index.php',
            'report_id' => 'deadbeef',
        ]);

        $reporter = $this->reporter(['r1' => ['content' => $content, 'mtime' => time()]]);
        $row = $reporter->getStatus()['recent']->getRows()[0]->getValue();

        $this->assertSame('Some domain error happened', $row['message']->getValue());
        $this->assertSame('/catalog/product/view/id/42', $row['url_path']->getValue());
    }

    public function testApiFatalErrorReportIsABareJsonStringWithNoUrl(): void
    {
        $content = json_encode("Fatal Error: 'Allowed memory size exhausted' in '/x.php' on line 10");

        $reporter = $this->reporter([], ['999' => ['content' => $content, 'mtime' => time()]]);
        $row = $reporter->getStatus()['recent']->getRows()[0]->getValue();

        $this->assertStringContainsString('Allowed memory size exhausted', $row['message']->getValue());
        $this->assertSame('', $row['url_path']->getValue());
        $this->assertSame('api/999', $row['name']->getValue());
    }

    public function testMessageIsRedactedBeforeBeingReported(): void
    {
        $content = json_encode([
            0 => 'Failed for user someone@example.com with token abcdefghijklmnopqrstuvwxyz123456',
            1 => 'trace',
        ]);

        $reporter = $this->reporter(['r1' => ['content' => $content, 'mtime' => time()]]);
        $message = $reporter->getStatus()['recent']->getRows()[0]->getValue()['message']->getValue();

        $this->assertStringNotContainsString('someone@example.com', $message);
        $this->assertStringContainsString('[email]', $message);
    }

    public function testTopMessagesGroupsNearDuplicatesAndCountsOccurrences(): void
    {
        $clock = $this->clockAt('2026-10-16 12:00:00');
        $ts = $clock->now()->getTimestamp();

        $orderMsg1 = json_encode([0 => 'Could not load order 10000123', 1 => 't']);
        $orderMsg2 = json_encode([0 => 'Could not load order 10000456', 1 => 't']);
        $other = json_encode([0 => 'Totally different problem', 1 => 't']);

        $reporter = $this->reporter(
            [
                'a' => ['content' => $orderMsg1, 'mtime' => $ts - 30],
                'b' => ['content' => $orderMsg2, 'mtime' => $ts - 10],
                'c' => ['content' => $other, 'mtime' => $ts - 20],
            ],
            [],
            $clock
        );

        $top = $reporter->getStatus()['top_messages']->getRows();

        $this->assertCount(2, $top);

        $first = $top[0]->getValue();
        $this->assertSame(2, $first['count']->getValue());
        $this->assertStringContainsString('Could not load order', $first['message']->getValue());
        // The more recent of the two grouped occurrences ($ts - 10) is kept as "last seen".
        $this->assertSame(gmdate('Y-m-d H:i:s', $ts - 10), $first['last_seen']->getValue());
    }

    public function testTopMessagesExcludesReportsOlderThan24h(): void
    {
        $clock = $this->clockAt('2026-10-16 12:00:00');
        $ts = $clock->now()->getTimestamp();
        $content = (string)json_encode([0 => 'Stale problem', 1 => 't']);

        $reporter = $this->reporter(
            ['old' => ['content' => $content, 'mtime' => $ts - (30 * 3600)]],
            [],
            $clock
        );

        $this->assertSame([], $reporter->getStatus()['top_messages']->getRows());
    }

    public function testCapHitWhenMoreCandidatesThanTheExaminationCap(): void
    {
        $webFiles = [];
        for ($i = 0; $i < 20001; $i++) {
            $webFiles['f' . $i] = ['content' => '', 'mtime' => $i];
        }

        $summary = $this->reporter($webFiles)->getStatus()['summary']->getFields();

        $this->assertTrue($summary['cap_hit']->getValue());
        $this->assertSame(20000, $summary['total']->getValue());
    }
}
