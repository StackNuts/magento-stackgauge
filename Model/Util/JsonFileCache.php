<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Util;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A small TTL cache of decoded JSON under var/, shared by the reporters that wrap a slow
 * external tool (PatchReporter, PatchInstallerReporter).
 *
 * Besides the TTL, an entry is tied to a "fingerprint" - the composer.lock mtime - so a
 * composer update/patch install invalidates it at once instead of reporting a stale answer
 * for up to a day. Never throws: a missing, corrupt, stale or wrong-shaped file is a miss.
 */
class JsonFileCache
{
    /**
     * @param Filesystem $filesystem
     * @param File $filesystemDriver
     * @param Json $json
     * @param Clock $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly File $filesystemDriver,
        private readonly Json $json,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The cached data at $relativePath (under var/), or null on any miss.
     *
     * @param string $relativePath
     * @param int $ttlSeconds
     * @param int|null $fingerprint the current composer.lock mtime (null when unknown)
     * @return array<string,mixed>|null
     */
    public function read(string $relativePath, int $ttlSeconds, ?int $fingerprint): ?array
    {
        try {
            $path = $this->absolutePath($relativePath);

            if (!$this->filesystemDriver->isExists($path)) {
                return null;
            }

            $decoded = $this->json->unserialize($this->filesystemDriver->fileGetContents($path));

            if (!is_array($decoded)
                || !is_int($decoded['recorded_at'] ?? null)
                || !is_array($decoded['data'] ?? null)
            ) {
                return null;
            }

            if (($decoded['lock_mtime'] ?? null) !== $fingerprint) {
                return null;
            }

            $age = $this->clock->now()->getTimestamp() - $decoded['recorded_at'];
            if ($age < 0 || $age >= $ttlSeconds) {
                return null;
            }

            return $decoded['data'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Best-effort write; a failure is logged and otherwise ignored.
     *
     * @param string $relativePath
     * @param array<string,mixed> $data
     * @param int|null $fingerprint
     * @return void
     */
    public function write(string $relativePath, array $data, ?int $fingerprint): void
    {
        try {
            $path = $this->absolutePath($relativePath);
            $dir = $this->filesystemDriver->getParentDirectory($path);

            if (!$this->filesystemDriver->isDirectory($dir)) {
                $this->filesystemDriver->createDirectory($dir);
            }

            $this->filesystemDriver->filePutContents(
                $path,
                $this->json->serialize([
                    'recorded_at' => $this->clock->now()->getTimestamp(),
                    'lock_mtime' => $fingerprint,
                    'data' => $data,
                ])
            );
        } catch (Throwable $e) {
            $this->logger->warning('StackGauge: could not write cache ' . $relativePath . ': ' . $e->getMessage());
        }
    }

    /**
     * @param string $relativePath
     */
    private function absolutePath(string $relativePath): string
    {
        return rtrim($this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath(), '/')
            . '/' . $relativePath;
    }
}
