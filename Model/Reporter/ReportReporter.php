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
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use StackNuts\StackGauge\Model\Util\Clock;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;
use Throwable;

/**
 * var/report is Magento's fatal-error signal: an uncaught exception (or, for a REST/SOAP/GraphQL
 * request, a true PHP fatal) in production mode shows the visitor "There has been an error
 * processing your request" and writes one file here instead of the error reaching system.log or
 * exception.log - LogReporter already covers those, this reporter is the one place a fatal that
 * never got logged still shows up.
 *
 * Two distinct shapes get written, both handled here:
 *  - var/report/<report_id> - a caught \Exception reaching Magento\Framework\App\ExceptionHandler,
 *    saved by Magento\Framework\Error\Processor::saveReport() as a JSON object
 *    {"0": message, "1": trace, "url": request_uri, "script_name": ..., "report_id": ...}.
 *  - var/report/api/<id> - a true PHP fatal (e.g. memory exhausted) during a webapi request,
 *    caught by Magento\Framework\Webapi\ErrorProcessor's shutdown function and saved as a bare
 *    JSON string "Fatal Error: '...' in '...' on line N" - no trace or url of its own.
 *
 * Only these two locations are read - a non-default MAGE_ERROR_REPORT_DIR_NESTING_LEVEL, which
 * spreads the first shape across 2-character hash subdirectories, is out of scope.
 */
class ReportReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '2.0';
    private const METRIC_NEW_LAST_HOUR = 'reports.new_last_hour';

    /**
     * Bounds how many var/report entries get stat()'d in one run - a runaway loop writing
     * thousands of reports must not turn this reporter itself into the next outage. "summary"
     * reports whether this cap was actually hit, so a real overflow is visible rather than
     * silently truncated.
     */
    private const MAX_FILES_EXAMINED = 20000;

    /**
     * Rows shown in the "recent" table.
     */
    private const RECENT_COUNT = 5;

    /**
     * Newest files whose content actually gets read (for both "recent" and "top_messages") -
     * each read is capped at READ_HEAD_BYTES, but this bounds how many of those reads happen at
     * all, since a report file's content is only needed for the handful of newest reports.
     */
    private const SCAN_COUNT = 50;

    /**
     * First N bytes read from a report file - a report's own message/trace/url live at the very
     * start of the file; a stack trace can otherwise run long, and this reporter must never load
     * one whole file, let alone SCAN_COUNT of them, into memory.
     */
    private const READ_HEAD_BYTES = 65536;

    /**
     * Longest "message" column value shown, matching the original extractMessage()'s own cap.
     */
    private const MESSAGE_MAX_LENGTH = 300;

    private const SECONDS_PER_HOUR = 3600;
    private const SECONDS_PER_DAY = 86400;

    /**
     * @param Filesystem $filesystem
     * @param Clock $clock
     * @param LogLineRedactor $redactor
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Clock $clock,
        private readonly LogLineRedactor $redactor,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the reports reporter.
     */
    public function getName(): string
    {
        return 'reports';
    }

    /**
     * Human-readable label for the reports reporter block.
     */
    public function getLabel(): string
    {
        return 'Reports';
    }

    /**
     * One-line summary of what the reports reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Counts, newest-first recent entries, and grouped messages from var/report - '
            . 'Magento\'s fatal "There has been an error processing your request" signal.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports var/report counts (total, last hour, last 24h, newest age), a table of the
     * SCAN_COUNT newest reports' newest RECENT_COUNT with distilled message/URL path, and the
     * most frequent normalised messages among reports from the last 24h.
     */
    public function getStatus(): array
    {
        $varDir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $now = $this->clock->now()->getTimestamp();

        $scan = $this->scanReportFiles($varDir);
        $files = $scan['files'];

        $hourAgo = $now - self::SECONDS_PER_HOUR;
        $dayAgo = $now - self::SECONDS_PER_DAY;

        $newLastHour = 0;
        $newLast24h = 0;
        foreach ($files as $file) {
            if ($file['mtime'] < $dayAgo) {
                // $files is sorted newest-first, so nothing after this point can be newer either.
                break;
            }

            $newLast24h++;
            if ($file['mtime'] >= $hourAgo) {
                $newLastHour++;
            }
        }

        $hasReports = $files !== [];
        $newestAgeMinutes = $hasReports ? max(0, intdiv($now - $files[0]['mtime'], 60)) : 0;

        $parsed = array_map(
            fn(array $file): array => $this->readAndParse($varDir, $file),
            array_slice($files, 0, self::SCAN_COUNT)
        );

        $recentRows = array_map(
            fn(array $p): ArrayField => $this->field->array($p['name'], [
                'name' => $this->field->varchar('Name', $p['name']),
                'created_at' => $this->field->datetime('Created At', $this->formatDateTime($p['mtime'])),
                'message' => $this->field->varchar('Message', $p['message']),
                'url_path' => $this->field->varchar('URL Path', $p['url_path']),
                'exception_class' => $this->field->varchar('Exception Class', $p['exception_class']),
            ]),
            array_slice($parsed, 0, self::RECENT_COUNT)
        );

        $topMessages = $this->groupMessages(
            array_filter($parsed, static fn(array $p): bool => $p['mtime'] >= $dayAgo)
        );

        return [
            'summary' => $this->section->facts('summary', 'Summary', $this->getDescription(), [
                'total' => $this->field->number('Total Report Files', count($files)),
                'new_last_hour' => $this->field->trackableNumber(
                    'New In Last Hour',
                    $newLastHour,
                    self::METRIC_NEW_LAST_HOUR,
                    MetricDefinition::AGGREGATION_LATEST
                ),
                'new_last_24h' => $this->field->number('New In Last 24h', $newLast24h),
                'has_reports' => $this->field->bool('Has Reports', $hasReports),
                'newest_age_minutes' => $this->field->number(
                    'Newest Report Age',
                    $newestAgeMinutes,
                    unit: Unit::MINUTES
                ),
                'cap_hit' => $this->field->bool('File Scan Capped', $scan['cap_hit']),
            ]),
            'recent' => $this->section->table('recent', 'Recent Reports', '', $recentRows),
            'top_messages' => $this->section->table(
                'top_messages',
                'Top Messages (Last 24h)',
                '',
                $topMessages,
                keyName: 'message'
            ),
        ];
    }

    /**
     * Alertable metric for the reports reporter: new fatal-error reports written in the last hour.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_NEW_LAST_HOUR,
                'Reports: New In Last Hour',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                // "new_last_hour" is itself a rolling count computed fresh every run, sampled
                // once per hour by default (this reporter declares no cadence/slot of its own,
                // so it runs hourly like most reporters - see Config::getSlotCount()). The
                // window is set to twice that run interval so one late or skipped cron tick
                // still leaves a sample inside the window, rather than reading as "no data".
                120,
                null,
                description: '{value} new fatal error reports were written in the last hour.',
                impact: "Customers saw Magento's error page.",
                criticalThreshold: 10
            ),
        ];
    }

    /**
     * Every candidate report file (top-level var/report plus var/report/api), stat'd for mtime
     * and sorted newest-first, capped at MAX_FILES_EXAMINED.
     *
     * @param ReadInterface $varDir
     * @return array{files: list<array{path: string, name: string, mtime: int}>, cap_hit: bool}
     */
    private function scanReportFiles(ReadInterface $varDir): array
    {
        $entries = $this->listCandidates($varDir);

        $capHit = count($entries) > self::MAX_FILES_EXAMINED;
        if ($capHit) {
            $entries = array_slice($entries, 0, self::MAX_FILES_EXAMINED);
        }

        $files = [];
        foreach ($entries as $entry) {
            try {
                $stat = $varDir->stat($entry['path']);
            } catch (Throwable) {
                // Gone since the directory listing was read (cleaned up, rotated) - just skip it.
                continue;
            }

            $files[] = [
                'path' => $entry['path'],
                'name' => $entry['name'],
                'mtime' => (int)($stat['mtime'] ?? 0),
            ];
        }

        usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return ['files' => $files, 'cap_hit' => $capHit];
    }

    /**
     * Every file directly under var/report, plus every file under var/report/api (see this
     * class's own docblock for why both). Directories encountered while listing either one
     * (namely "api" itself, and any hash subdirectory from a non-default nesting level) are
     * skipped rather than descended into.
     *
     * @param ReadInterface $varDir
     * @return list<array{path: string, name: string}>
     */
    private function listCandidates(ReadInterface $varDir): array
    {
        $entries = [];

        foreach (['report' => '', 'report/api' => 'api/'] as $dir => $namePrefix) {
            try {
                if (!$varDir->isExist($dir)) {
                    continue;
                }

                foreach ($varDir->read($dir) as $path) {
                    if (!$varDir->isFile($path)) {
                        continue;
                    }

                    $entries[] = ['path' => $path, 'name' => $namePrefix . basename($path)];
                }
            } catch (Throwable) {
                // Best-effort - an unreadable report directory must not fail the whole reporter.
                continue;
            }
        }

        return $entries;
    }

    /**
     * Reads one file's head (READ_HEAD_BYTES) and distills it into the display fields the
     * "recent" and "top_messages" sections both need.
     *
     * @param ReadInterface $varDir
     * @param array{path: string, name: string, mtime: int} $file
     * @return array{name: string, mtime: int, message: string, url_path: string, exception_class: string}
     */
    private function readAndParse(ReadInterface $varDir, array $file): array
    {
        $content = $this->readHead($varDir, $file['path']);
        $parsed = $this->parseReportContent($content);

        return [
            'name' => $file['name'],
            'mtime' => $file['mtime'],
            'message' => $this->distillMessage($parsed['message']),
            'url_path' => $this->urlPath($parsed['url']),
            'exception_class' => $this->extractExceptionClass($parsed['message']),
        ];
    }

    /**
     * Up to READ_HEAD_BYTES from the start of $path, or '' on any read failure (missing,
     * unreadable, removed mid-scan) - a single bad report file must never fail this reporter.
     *
     * @param ReadInterface $varDir
     * @param string $path
     */
    private function readHead(ReadInterface $varDir, string $path): string
    {
        try {
            $stream = $varDir->openFile($path);

            try {
                return (string)$stream->read(self::READ_HEAD_BYTES);
            } finally {
                $stream->close();
            }
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Pulls the message and request URL out of one report file's raw (head-only) content.
     *
     * Handles both shapes this class's own docblock describes: a JSON object with a "0" message
     * key and optional "url" (the web report.php shape), or a bare JSON string (the webapi fatal
     * shape, which has no url of its own). Anything else - not valid JSON, or valid JSON that
     * doesn't yield a message - falls back to the original regex-based extraction this reporter
     * has always used, so a hand-edited or unexpectedly-shaped file still shows something.
     *
     * @param string $content
     * @return array{message: string, url: string}
     */
    private function parseReportContent(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['message' => '', 'url' => ''];
        }

        $message = '';
        $url = '';

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_string($decoded)) {
                $message = $decoded;
            } elseif (is_array($decoded)) {
                $message = (string)($decoded[0] ?? $decoded['message'] ?? '');
                $url = (string)($decoded['url'] ?? '');
            }
        }

        if ($message === '') {
            $message = $this->regexExtractMessage($content);
        }

        return ['message' => $message, 'url' => $url];
    }

    /**
     * Pulls a short exception/error message out of raw report content, falling back to a plain
     * excerpt - the original extraction this reporter used before it understood var/report's
     * actual JSON shapes.
     *
     * @param string $content
     */
    private function regexExtractMessage(string $content): string
    {
        if (preg_match('/(?:Exception|Error):\s*(.{1,300})/m', $content, $m)) {
            return trim($m[1]);
        }

        return mb_substr($content, 0, 200);
    }

    /**
     * A leading "Some\Class\Name: " or "SomeException: " prefix off $message, if there is one -
     * $message is normally just \Throwable::getMessage(), which doesn't carry its own class name,
     * so this is best-effort and often comes back empty.
     *
     * @param string $message
     */
    private function extractExceptionClass(string $message): string
    {
        if (preg_match('/^((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error))\s*:/', $message, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * $message's first line only, truncated to MESSAGE_MAX_LENGTH and redacted - a report's
     * message/trace can carry whatever the request did (payloads, emails), same as a log line.
     *
     * @param string $message
     */
    private function distillMessage(string $message): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $message, 2);
        $firstLine = trim($lines[0] ?? '');

        return $this->redactor->redact(mb_substr($firstLine, 0, self::MESSAGE_MAX_LENGTH));
    }

    /**
     * $url's path component only - no host, no query string, since a query string routinely
     * carries customer data (emails, tokens) that must never leave the store.
     *
     * @param string $url
     */
    private function urlPath(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * UTC "Y-m-d H:i:s" for a unix timestamp, matching Field::datetime()'s own contract.
     *
     * @param int $timestamp
     */
    private function formatDateTime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Groups $parsedFiles (already distilled by readAndParse()) by a digit/hex-normalised
     * message, most frequent first, each row keeping one representative (its most recent
     * occurrence's) message plus a count and last-seen time.
     *
     * @param array<int, array{name: string, mtime: int, message: string, url_path: string, exception_class: string}> $parsedFiles
     * @return list<ArrayField>
     */
    private function groupMessages(array $parsedFiles): array
    {
        $groups = [];

        foreach ($parsedFiles as $p) {
            $key = $this->normalizeForGrouping($p['message']);

            if (!isset($groups[$key])) {
                $groups[$key] = ['message' => $p['message'], 'count' => 0, 'last_seen' => 0];
            }

            $groups[$key]['count']++;
            if ($p['mtime'] > $groups[$key]['last_seen']) {
                $groups[$key]['last_seen'] = $p['mtime'];
                $groups[$key]['message'] = $p['message'];
            }
        }

        usort(
            $groups,
            static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: ($b['last_seen'] <=> $a['last_seen'])
        );

        return array_map(
            fn(array $g): ArrayField => $this->field->array($g['message'], [
                'message' => $this->field->varchar('Message', $g['message']),
                'count' => $this->field->number('Count', $g['count']),
                'last_seen' => $this->field->datetime('Last Seen', $this->formatDateTime($g['last_seen'])),
            ]),
            $groups
        );
    }

    /**
     * $message with digit runs and hex-looking ids (6+ hex chars, e.g. an order increment id or
     * a request id) collapsed to "#" - so "Could not load order 10000123" and "Could not load
     * order 10000456" group together instead of each getting their own one-off row.
     *
     * @param string $message
     */
    private function normalizeForGrouping(string $message): string
    {
        $normalized = preg_replace('/\b[0-9a-f]{6,}\b/i', '#', $message);
        $normalized = preg_replace('/\d+/', '#', (string)$normalized);

        return trim((string)$normalized);
    }

    /**
     * Run 1 within this reporter's cadence tier - this reporter now does real file I/O
     * (stat'ing up to MAX_FILES_EXAMINED entries, reading the head of up to SCAN_COUNT of
     * them), so it no longer belongs in the large default slot 0 alongside every lightweight
     * reporter; slot 2 already carries LogReporter's own file I/O, so this uses slot 1 instead.
     */
    public function getSlot(): int
    {
        return 1;
    }
}
