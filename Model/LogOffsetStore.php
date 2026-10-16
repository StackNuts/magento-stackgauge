<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Tiny per-file read cursor used by Reporter\LogReporter to read only newly-appended bytes on
 * each run instead of streaming the whole file every time. One row per monitored log file
 * name - every file this module reads lives under var/log via DirectoryList::LOG, so the name
 * alone is already a stable, unique key; a raw ResourceConnection upsert is simpler than a
 * full Model/ResourceModel pair for what's really just a tiny key-value store.
 *
 * Cursors are staged, not written: LogReporter calls stage() while it builds a payload, and
 * only ReportSender calls commit() once the dashboard has accepted that payload. A preview
 * (dry run, "build only") or a failed send leaves the stored cursor untouched, so those lines
 * are read again on the next real run instead of being lost. discard() drops anything staged.
 *
 * To detect a rotated file whose new content has already grown past the old offset, each
 * cursor carries a fingerprint: the CRC32 of the file's first bytes and how many bytes were
 * hashed, stored as "<crc hex>:<length hex>" in the nullable `fingerprint` column. NULL means a
 * row written by an older version: it reads fine, it just has no fingerprint to check.
 */
class LogOffsetStore
{
    private const TABLE = 'stacknuts_stackgauge_log_offset';

    /**
     * @var array<string, array{byte_offset: int, running_count: int, fingerprint_crc: int, fingerprint_length: int}>
     */
    private array $staged = [];

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * The committed cursor for $filePath, or null if this file has never been read before.
     *
     * fingerprint_length is 0 (and the crc meaningless) when no fingerprint was ever stored.
     *
     * @param string $filePath
     * @return array{byte_offset: int, running_count: int, fingerprint_crc: int, fingerprint_length: int}|null
     */
    public function get(string $filePath): ?array
    {
        $row = $this->readRow($filePath);
        if ($row === null) {
            return null;
        }

        $parts = $row['fingerprint'] !== null ? explode(':', $row['fingerprint']) : [];
        $valid = count($parts) === 2 && ctype_xdigit($parts[0]) && ctype_xdigit($parts[1]);

        return [
            'byte_offset' => $row['byte_offset'],
            'running_count' => $row['running_count'],
            'fingerprint_crc' => $valid ? (int)hexdec($parts[0]) : 0,
            'fingerprint_length' => $valid ? (int)hexdec($parts[1]) : 0,
        ];
    }

    /**
     * Remembers the new cursor for $filePath in memory only; nothing is written until commit().
     *
     * @param string $filePath
     * @param int $byteOffset
     * @param int $runningCount
     * @param int $fingerprintCrc CRC32 of the file's first $fingerprintLength bytes.
     * @param int $fingerprintLength
     */
    public function stage(
        string $filePath,
        int $byteOffset,
        int $runningCount,
        int $fingerprintCrc = 0,
        int $fingerprintLength = 0
    ): void {
        $this->staged[$filePath] = [
            'byte_offset' => $byteOffset,
            'running_count' => $runningCount,
            'fingerprint_crc' => $fingerprintCrc,
            'fingerprint_length' => $fingerprintLength,
        ];
    }

    /**
     * Writes every staged cursor and clears the staging area. Call only after a successful send.
     */
    public function commit(): void
    {
        $staged = $this->staged;
        $this->staged = [];

        foreach ($staged as $filePath => $cursor) {
            $this->writeRow(
                $filePath,
                $cursor['byte_offset'],
                $cursor['running_count'],
                $cursor['fingerprint_length'] > 0
                    ? sprintf('%x:%x', $cursor['fingerprint_crc'], $cursor['fingerprint_length'])
                    : null
            );
        }
    }

    /**
     * Drops every staged cursor without writing it.
     */
    public function discard(): void
    {
        $this->staged = [];
    }

    /**
     * One stored row, or null when there is none.
     *
     * @param string $key
     * @return array{byte_offset: int, running_count: int, fingerprint: string|null}|null
     */
    protected function readRow(string $key): ?array
    {
        $connection = $this->resourceConnection->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE), ['byte_offset', 'running_count', 'fingerprint'])
                ->where('file_path = ?', $key)
        );

        if ($row === false) {
            return null;
        }

        return [
            'byte_offset' => (int)$row['byte_offset'],
            'running_count' => (int)$row['running_count'],
            'fingerprint' => isset($row['fingerprint']) ? (string)$row['fingerprint'] : null,
        ];
    }

    /**
     * Upserts one row.
     *
     * @param string $key
     * @param int $byteOffset
     * @param int $runningCount
     * @param string|null $fingerprint
     */
    protected function writeRow(string $key, int $byteOffset, int $runningCount, ?string $fingerprint): void
    {
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'file_path' => $key,
                'byte_offset' => $byteOffset,
                'running_count' => $runningCount,
                'fingerprint' => $fingerprint,
            ],
            ['byte_offset', 'running_count', 'fingerprint']
        );
    }
}
