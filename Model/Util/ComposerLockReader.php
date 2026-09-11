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
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Json $json
    ) {
    }

    public function getRawContents(): ?string
    {
        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);

        return $root->isExist('composer.lock') ? $root->readFile('composer.lock') : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDecoded(): ?array
    {
        $contents = $this->getRawContents();
        if ($contents === null) {
            return null;
        }

        try {
            return $this->json->unserialize($contents);
        } catch (Throwable) {
            return null;
        }
    }
}
