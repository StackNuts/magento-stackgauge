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
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\LogOffsetStore;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
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
 * via a byte offset persisted per file name in LogOffsetStore - the first time a file is seen
 * (or after it's been rotated/truncated, detected by the stored offset exceeding the file's
 * current size) it's read from byte 0, exactly like every run used to behave. A real
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
    use PlatformSectionTrait;

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
            // system.log and exception.log were already streamed above (each exactly once, to
            // avoid reading past its own just-saved offset) - reuse those tails rather than
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

        if ($result['has_file']) {
            $this->logOffsetStore->save($file, $result['offset'], 0);
        }

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

        if ($result['has_file']) {
            $this->logOffsetStore->save($file, $result['offset'], 0);
        }

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

        if ($result['has_file']) {
            $this->logOffsetStore->save($file, $result['offset'], $total);
        }

        return ['total' => $total, 'recent' => $recent, 'tail' => $tail];
    }

    /**
     * Streams only the bytes appended to $file since the last saved offset for it, calling
     * $onLine for each new line with its line-ending stripped. The first time a file is seen
     * (no stored offset yet), or when the stored offset exceeds the file's current size (it
     * was rotated or truncated since last read), this reads from byte 0 instead - identical to
     * every run's behavior before offsets existed. A missing file or any read error is treated
     * the same as an empty file with nothing new - this reporter must never fail a whole report
     * over one unreadable log, and must never advance or lose a saved offset over a failed read.
     *
     * When the stored offset already equals the file's current size (nothing has been
     * appended since last run - the common case on a quiet file), this skips opening the file
     * at all rather than seeking a stream exactly to its own current end: Magento's own
     * Filesystem\File\Read::eof() doesn't reliably agree it's at EOF right after such a seek
     * (no read has actually touched that position yet to set its internal flag), and the
     * readLine() this loop would then call throws FileSystemException instead of returning
     * false - confirmed against a real multi-hundred-MB exception.log, not a hypothetical.
     * Reading sequentially *through* to a file's true end (the startOffset < size case below)
     * doesn't hit this, since by the time eof() is checked a real read has already landed on
     * it.
     *
     * Deliberately does not persist the new offset itself - callers that also need to persist
     * a running_count alongside it (see scanExceptionLog()) would otherwise have no way to
     * combine both into a single save() call.
     *
     * @param ReadInterface $dir
     * @param string $file
     * @param callable $onLine
     * @return array{offset: int, previous_running_count: int, has_file: bool}
     */
    private function streamNewLines(ReadInterface $dir, string $file, callable $onLine): array
    {
        try {
            if (!$dir->isExist($file)) {
                return ['offset' => 0, 'previous_running_count' => 0, 'has_file' => false];
            }

            $size = (int)($dir->stat($file)['size'] ?? 0);
            $state = $this->logOffsetStore->get($file);
            $startOffset = ($state !== null && $state['byte_offset'] <= $size) ? $state['byte_offset'] : 0;

            if ($state !== null && $startOffset === $size) {
                return [
                    'offset' => $startOffset,
                    'previous_running_count' => $state['running_count'],
                    'has_file' => true,
                ];
            }

            $stream = $dir->openFile($file);

            try {
                if ($startOffset > 0) {
                    $stream->seek($startOffset);
                }

                while (!$stream->eof()) {
                    $line = $stream->readLine(self::MAX_LINE_LENGTH);
                    $onLine(rtrim($line, "\r\n"));
                }

                return [
                    'offset' => (int)$stream->tell(),
                    'previous_running_count' => $state['running_count'] ?? 0,
                    'has_file' => true,
                ];
            } finally {
                $stream->close();
            }
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (Throwable) {
            // Best-effort - an unreadable log must not fail this reporter or the whole report,
            // and must not advance a saved offset over a read it didn't actually complete.
            return ['offset' => 0, 'previous_running_count' => 0, 'has_file' => false];
        }
    }

    /**
     * Run 2 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 2;
    }
}
