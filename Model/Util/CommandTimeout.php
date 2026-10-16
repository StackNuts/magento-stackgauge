<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Util;

/**
 * Prefix for shell commands that must not hang a cron run: `timeout N ` when the binary
 * exists on this host, otherwise an empty string (the command then runs unbounded, as before).
 */
class CommandTimeout
{
    /**
     * @param int $seconds
     */
    public function prefix(int $seconds): string
    {
        $dirs = array_merge(explode(':', (string)getenv('PATH')), ['/usr/bin', '/bin', '/usr/local/bin']);

        foreach ($dirs as $dir) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            if ($dir !== '' && is_executable($dir . '/timeout')) {
                return 'timeout ' . $seconds . ' ';
            }
        }

        return '';
    }
}
