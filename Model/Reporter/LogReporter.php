<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\File\ReadInterface as FileReadInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\LogOffsetStore;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;
use Throwable;

/**
 * exception.log is always parsed for its own distilled "Recent exceptions" list and
 * trackable occurrence count, regardless of admin configuration - that behavior is specific
 * to this one file's content, not something an admin can switch off. Which other files get a
 * raw recent-lines tail is admin-controlled via System Configuration > Advanced > StackGauge
 * > Agent > "Monitored Log Files" (see Config::getMonitoredLogFiles()).
 *
 * Every run reads only the bytes appended to a file since this reporter last looked at it,
 * via a byte offset kept per file name in LogOffsetStore. The offsets are only staged here;
 * ReportSender commits them after the dashboard accepted the report, so a preview or a failed
 * send never swallows lines. A file never seen before is read from at most its last
 * FIRST_READ_MAX_BYTES; a rotated or truncated file (offset past the end, or the fingerprint
 * of its first bytes no longer matching the stored one) is read again from the start, bounded
 * the same way. A real
 * exception.log on a long-running store can reach hundreds of MB; rereading it whole on every
 * run reliably exhausted PHP-FPM's memory_limit (CLI/cron kept working throughout, which is
 * why this only ever surfaced via the admin "Send Now" button) and, even memory-safe, wasted
 * I/O that grew with the file's lifetime size rather than with how much was actually new.
 *
 * One consequence of reading only what's new: "recent exceptions" and each monitored file's
 * line tail show what appeared since the last run, not a constant rehash of the same
 * historical lines every time - they can be empty on a quiet run, which just means nothing
 * new happened, not that collection stopped. exception.log's own occurrence count is the one
 * value that still needs to read as a stable running total (see getTrackableMetrics() -
 * AGGREGATION_DELTA expects a monotonically growing counter) - LogOffsetStore carries that
 * total forward across runs by storing it alongside the byte offset, so accurate as a single
 * full scan today, this reporter just adds each run's newly-found count to it instead of
 * recomputing the whole thing from scratch.
 */
class LogReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.1';
    private const METRIC_EXCEPTION_COUNT = 'logs.exception_count';

    private const SYSTEM_LOG = 'system.log';
    private const EXCEPTION_LOG = 'exception.log';

    /**
     * Enough to feel like a real log tail without ballooning the payload - ArrayField's own
     * MAX_ITEMS (500) is far more than any of these files' section ever needs.
     */
    private const TAIL_LINE_COUNT = 50;

    /**
     * Generous cap for a single line (a stack trace frame, say) - bounds worst-case memory
     * for one readLine() call without truncating anything realistic.
     */
    private const MAX_LINE_LENGTH = 65536;

    /**
     * The most a file seen for the first time (or after rotation) is read back: the last 1 MB.
     */
    private const FIRST_READ_MAX_BYTES = 1048576;

    /**
     * How many leading bytes are hashed to recognise the same file across runs.
     */
    private const FINGERPRINT_BYTES = 512;

    /**
     * @param Filesystem $filesystem
     * @param Config $config
     * @param LogOffsetStore $logOffsetStore
     * @param Field $field
     * @param Section $section
     * @param LogLineRedactor $redactor
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Config $config,
        private readonly LogOffsetStore $logOffsetStore,
        private readonly Field $field,
        private readonly Section $section,
        private readonly LogLineRedactor $redactor
    ) {
    }

    /**
     * Payload key for the logs reporter.
     */
    public function getName(): string
    {
        return 'logs';
    }

    /**
     * Human-readable label for the logs reporter block.
     */
    public function getLabel(): string
    {
        return 'Logs';
    }

    /**
     * One-line summary of what the logs reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Recent log activity and exception summaries.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports system.log new-line count, exception.log's distilled summary, and a raw tail per admin-monitored file.
     *
     * All since the last run, not the whole file - see this class's own docblock.
     */
    public function getStatus(): array
    {
        $logDir = $this->filesystem->getDirectoryRead(DirectoryList::LOG);

        $systemScan = $this->scanSystemLog($logDir, self::SYSTEM_LOG);
        $exceptionScan = $this->scanExceptionLog($logDir, self::EXCEPTION_LOG);

        $sections = [
            'general' => $this->section->facts('general', 'General', '', [
                'system_new_lines' => $this->field->number('System new lines', $systemScan['new_lines']),
                'exception_count' => $this->field->trackableNumber(
                    'Exception Count',
                    $exceptionScan['total'],
                    self::METRIC_EXCEPTION_COUNT,
                    MetricDefinition::AGGREGATION_DELTA
                ),
            ]),
            // No keyName override: identical exception messages recurring in the log window
            // are common and not a reporter bug, so this deliberately skips the
            // duplicate-row check (which needs every row to share the missing "name" column).
            // Every line that leaves the store goes through the redactor first - log text
            // routinely carries customer emails, request payloads and credentials.
            'recent_exceptions' => $this->section->table('recent_exceptions', 'Recent exceptions', '', array_map(
                fn($m) => $this->field->array($m, [
                    'message' => $this->field->varchar('Message', $m),
                ]),
                array_map($this->redactor->redact(...), $exceptionScan['recent'])
            )),
        ];

        foreach ($this->config->getMonitoredLogFiles() as $file => $name) {
            $key = $this->tailSectionKey($file);
            // system.log and exception.log were already streamed above (each exactly once) - reuse those tails rather than
            // streaming either file a second time, which would otherwise see nothing new left
            // to read.
            $lines = match ($file) {
                self::SYSTEM_LOG => $systemScan['tail'],
                self::EXCEPTION_LOG => $exceptionScan['tail'],
                default => $this->newTailLines($logDir, $file),
            };
            $sections[$key] = $this->section->table($key, $name, '', array_map(
                fn(string $line) => $this->field->array('', ['line' => $this->field->varchar('Line', $line)]),
                array_map($this->redactor->redact(...), $lines)
            ));
        }

        return $sections;
    }

    /**
     * Alertable metric for the logs reporter: new exception.log occurrences since the last sample.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // exception.log is append-only between rotations, so AGGREGATION_DELTA over the
            // window yields "how many new exception occurrences" since the last sample.
            new MetricDefinition(
                self::METRIC_EXCEPTION_COUNT,
                'Logs: Exception Count',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                20,
                360,
                null,
                description: '{value} exceptions were logged in the last {window}, above {threshold}.',
                impact: 'Something in the code is failing repeatedly.',
                criticalThreshold: 200
            ),
        ];
    }

    /**
     * Derives a payload section key from a monitored log file name, e.g. "system.log" -> "tail_system_log".
     *
     * @param string $file
     */
    private function tailSectionKey(string $file): string
    {
        return 'tail_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($file));
    }

    /**
     * Up to the last TAIL_LINE_COUNT non-empty lines appended to $file since this reporter last read it.
     *
     * @param ReadInterface $dir
     * @param string $file
     * @return list<string>
     */
    private function newTailLines(ReadInterface $dir, string $file): array
    {
        $tail = [];

        $result = $this->streamNewLines($dir, $file, function (string $line) use (&$tail): void {
            if ($line === '') {
                return;
            }

            $tail[] = $line;
            if (count($tail) > self::TAIL_LINE_COUNT) {
                array_shift($tail);
            }
        });

        $this->stageCursor($file, $result, 0);

        return $tail;
    }

    /**
     * One streaming pass doing both of system.log's jobs at once over just the bytes appended
     * since last read: a count of every new line (blank or not, matching this field's original
     * whole-file meaning) and the tail of up to the last TAIL_LINE_COUNT new non-empty lines,
     * reused for system.log's monitored-file tail section if configured. Combining both into
     * one pass (rather than two separate streamNewLines() calls) matters here specifically
     * because the second call would otherwise start from the offset the first call just saved,
     * finding nothing new left to read.
     *
     * @param ReadInterface $dir
     * @param string $file
     * @return array{new_lines: int, tail: list<string>}
     */
    private function scanSystemLog(ReadInterface $dir, string $file): array
    {
        $count = 0;
        $tail = [];

        $result = $this->streamNewLines($dir, $file, function (string $line) use (&$count, &$tail): void {
            $count++;

            if ($line === '') {
                return;
            }

            $tail[] = $line;
            if (count($tail) > self::TAIL_LINE_COUNT) {
                array_shift($tail);
            }
        });

        $this->stageCursor($file, $result, 0);

        return ['new_lines' => $count, 'tail' => $tail];
    }

    /**
     * One streaming pass doing all three exception.log jobs at once over just the bytes
     * appended since last read (total occurrence count, up to 5 unique new recent messages,
     * last 50 new raw lines). "total" is a running count carried forward via LogOffsetStore -
     * see this class's own docblock for why that one field needs to keep reading as a stable
     * monotonically growing total rather than "what's new this run" like everything else here.
     *
     * @param ReadInterface $dir
     * @param string $file
     * @return array{total: int, recent: string[], tail: list<string>}
     */
    private function scanExceptionLog(ReadInterface $dir, string $file): array
    {
        $newMatches = 0;
        $recent = [];
        $tail = [];

        $result = $this->streamNewLines(
            $dir,
            $file,
            function (string $line) use (&$newMatches, &$recent, &$tail): void {
                if ($line !== '') {
                    $tail[] = $line;
                    if (count($tail) > self::TAIL_LINE_COUNT) {
                        array_shift($tail);
                    }
                }

                if (preg_match('/(?:Exception|Error):\s*(.{5,200})/', $line, $m)) {
                    $newMatches++;
                    $message = trim($m[1]);
                    if (count($recent) < 5 && !in_array($message, $recent, true)) {
                        $recent[] = $message;
                    }
                }
            }
        );

        $total = $result['previous_running_count'] + $newMatches;

        $this->stageCursor($file, $result, $total);

        return ['total' => $total, 'recent' => $recent, 'tail' => $tail];
    }

    /**
     * Stages $result's cursor for $file (committed later by ReportSender after a good send).
     *
     * @param string $file
     * @param array{offset: int, previous_running_count: int, has_file: bool, fingerprint_crc: int, fingerprint_length: int} $result
     * @param int $runningCount
     */
    private function stageCursor(string $file, array $result, int $runningCount): void
    {
        if ($result['has_file']) {
            $this->logOffsetStore->stage(
                $file,
                $result['offset'],
                $runningCount,
                $result['fingerprint_crc'],
                $result['fingerprint_length']
            );
        }
    }

    /**
     * Streams only the bytes appended to $file since the last committed offset for it, calling
     * $onLine for each new line with its line-ending stripped.
     *
     * Where reading starts: the stored offset when the file is the one that offset belongs to;
     * otherwise (never seen, offset past the end, or the fingerprint of the first bytes no
     * longer matches - it was rotated or truncated and may already have outgrown the old
     * offset) the start of the file, but never further back than FIRST_READ_MAX_BYTES from the
     * end, so a multi-GB log cannot stall a run. When that lands mid-line, the partial first
     * line is dropped. A row written by an older version has no fingerprint, and is trusted
     * as long as its offset is within the file.
     *
     * Lines are read with an explicit "\n" ending (without one, stream_get_line returns
     * 64 KB blocks) and the loop runs until tell() reaches the size stat() reported, not until
     * eof(): after the final newline stream_get_line() returns false before eof() flips, and
     * Magento turns that false into an exception.
     *
     * When the stored offset already equals the file's current size (nothing appended - the
     * common case on a quiet file) the file isn't opened at all.
     *
     * A missing or unreadable file reports "nothing new" and carries the stored running count
     * forward unchanged (so a log rotated away doesn't make the total drop to 0 and then jump
     * back). Nothing is persisted here: callers stage the result, see stageCursor().
     *
     * @param ReadInterface $dir
     * @param string $file
     * @param callable $onLine
     * @return array{offset: int, previous_running_count: int, has_file: bool, fingerprint_crc: int, fingerprint_length: int}
     */
    private function streamNewLines(ReadInterface $dir, string $file, callable $onLine): array
    {
        $state = null;
        $unchanged = [
            'offset' => 0,
            'previous_running_count' => 0,
            'has_file' => false,
            'fingerprint_crc' => 0,
            'fingerprint_length' => 0,
        ];

        try {
            $state = $this->logOffsetStore->get($file);
            $unchanged['previous_running_count'] = $state['running_count'] ?? 0;

            if (!$dir->isExist($file)) {
                return $unchanged;
            }

            $size = (int)($dir->stat($file)['size'] ?? 0);

            if ($state !== null && $state['byte_offset'] === $size && $state['fingerprint_length'] === 0) {
                return [
                    'offset' => $size,
                    'previous_running_count' => $state['running_count'],
                    'has_file' => true,
                    'fingerprint_crc' => 0,
                    'fingerprint_length' => 0,
                ] + $unchanged;
            }

            $stream = $dir->openFile($file);

            try {
                $sameFile = $state !== null
                    && $state['byte_offset'] <= $size
                    && $this->fingerprintMatches($stream, $state);

                if ($sameFile && $state['byte_offset'] === $size) {
                    return [
                        'offset' => $size,
                        'previous_running_count' => $state['running_count'],
                        'has_file' => true,
                        'fingerprint_crc' => $state['fingerprint_crc'],
                        'fingerprint_length' => $state['fingerprint_length'],
                    ];
                }

                $startOffset = $sameFile ? $state['byte_offset'] : max(0, $size - self::FIRST_READ_MAX_BYTES);

                // Always seek: fingerprintMatches() left the stream somewhere near the start.
                $stream->seek($startOffset);
                if ($startOffset > 0 && !$sameFile) {
                    // Landed mid-line: drop the partial first line.
                    $stream->readLine(self::MAX_LINE_LENGTH, "\n");
                }

                while ((int)$stream->tell() < $size) {
                    $line = $stream->readLine(self::MAX_LINE_LENGTH, "\n");
                    $onLine(rtrim($line, "\r\n"));
                }

                $offset = (int)$stream->tell();
                $fingerprint = $this->fingerprint($stream, $offset);

                return [
                    'offset' => $offset,
                    'previous_running_count' => $state['running_count'] ?? 0,
                    'has_file' => true,
                    'fingerprint_crc' => $fingerprint['crc'],
                    'fingerprint_length' => $fingerprint['length'],
                ];
            } finally {
                $stream->close();
            }
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (Throwable) {
            // Best-effort - an unreadable log must not fail this reporter or the whole report,
            // and must not advance a stored offset over a read it didn't actually complete.
            return $unchanged;
        }
    }

    /**
     * Whether the first bytes of the open file still hash to the stored fingerprint; true when
     * none was stored (a row from an older version).
     *
     * @param FileReadInterface $stream
     * @param array{fingerprint_crc: int, fingerprint_length: int} $state
     */
    private function fingerprintMatches(FileReadInterface $stream, array $state): bool
    {
        if ($state['fingerprint_length'] === 0) {
            return true;
        }

        $stream->seek(0);
        $head = (string)$stream->read($state['fingerprint_length']);

        return strlen($head) === $state['fingerprint_length'] && crc32($head) === $state['fingerprint_crc'];
    }

    /**
     * CRC32 of the file's first min($upTo, FINGERPRINT_BYTES) bytes, and how many were hashed.
     *
     * @param FileReadInterface $stream
     * @param int $upTo
     * @return array{crc: int, length: int}
     */
    private function fingerprint(FileReadInterface $stream, int $upTo): array
    {
        $length = min($upTo, self::FINGERPRINT_BYTES);
        if ($length <= 0) {
            return ['crc' => 0, 'length' => 0];
        }

        $stream->seek(0);
        $head = (string)$stream->read($length);

        return ['crc' => crc32($head), 'length' => strlen($head)];
    }

    /**
     * Run 2 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 2;
    }
}
