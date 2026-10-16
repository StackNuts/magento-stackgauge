<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Util;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use Throwable;

/**
 * Reads and parses the project root's composer.lock, shared by every reporter that needs
 * something out of it. Exposes only "raw contents" and "decoded" - callers do their own
 * filtering since what each needs from the decoded data differs. A missing or malformed
 * composer.lock is never an error here - both methods return null rather than throwing, so a
 * broken lock file doesn't stop a reporter from reporting whatever else it still can.
 */
class ComposerLockReader
{
    /**
     * @var bool
     */
    private bool $rawLoaded = false;

    /**
     * @var string|null
     */
    private ?string $raw = null;

    /**
     * @var bool
     */
    private bool $decodedLoaded = false;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $decoded = null;

    /**
     * @param Filesystem $filesystem
     * @param Json $json
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Json $json
    ) {
    }

    /**
     * The raw composer.lock contents, or null if the file doesn't exist.
     *
     * Read once per process: the lock is ~1 MB and several reporters plus the payload
     * envelope all need it within one send.
     */
    public function getRawContents(): ?string
    {
        if (!$this->rawLoaded) {
            $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
            $this->raw = $root->isExist('composer.lock') ? $root->readFile('composer.lock') : null;
            $this->rawLoaded = true;
        }

        return $this->raw;
    }

    /**
     * The parsed composer.lock, or null if it is missing or isn't valid JSON (decoded once per process).
     *
     * @return array<string,mixed>|null
     */
    public function getDecoded(): ?array
    {
        if (!$this->decodedLoaded) {
            $contents = $this->getRawContents();

            try {
                $decoded = $contents === null ? null : $this->json->unserialize($contents);
                $this->decoded = is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                $this->decoded = null;
            }
            $this->decodedLoaded = true;
        }

        return $this->decoded;
    }

    /**
     * composer.lock's modification time (unix), or null if unknown - used to invalidate caches
     * of anything derived from the installed packages.
     */
    public function getMtime(): ?int
    {
        try {
            $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
            if (!$root->isExist('composer.lock')) {
                return null;
            }
            $mtime = $root->stat('composer.lock')['mtime'] ?? null;

            return is_int($mtime) ? $mtime : null;
        } catch (Throwable) {
            return null;
        }
    }
}
