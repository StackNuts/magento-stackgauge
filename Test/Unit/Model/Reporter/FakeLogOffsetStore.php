<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use StackNuts\StackGauge\Model\LogOffsetStore;

/**
 * In-memory LogOffsetStore double - a real one needs a database. Only the two row-level
 * persistence methods are replaced, so LogReporterTest exercises the real staging/commit logic.
 */
class FakeLogOffsetStore extends LogOffsetStore
{
    /**
     * @var array<string, array{byte_offset: int, running_count: int, fingerprint: string|null}>
     */
    private array $rows = [];

    public function __construct()
    {
        // Deliberately skips the parent constructor - no ResourceConnection to give it, and
        // this fake never needs one. Staging/commit/fingerprint logic is the real parent code.
    }

    protected function readRow(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    protected function writeRow(string $key, int $byteOffset, int $runningCount, ?string $fingerprint): void
    {
        $this->rows[$key] = ['byte_offset' => $byteOffset, 'running_count' => $runningCount, 'fingerprint' => $fingerprint];
    }

    /**
     * Simulates an install upgraded from a version with no fingerprint rows.
     */
    public function seedLegacy(string $filePath, int $byteOffset, int $runningCount): void
    {
        $this->rows[$filePath] = ['byte_offset' => $byteOffset, 'running_count' => $runningCount, 'fingerprint' => null];
    }
}
