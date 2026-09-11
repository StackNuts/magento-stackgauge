<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Util;

/**
 * The one place "now" gets constructed, always pinned to UTC explicitly - relying on PHP's
 * ambient default timezone is unsafe here, since
 * Magento\Framework\Stdlib\DateTime\Timezone mutates it as a side effect of resolving the
 * admin/store timezone elsewhere in the request. Injectable so tests can substitute a fixed
 * clock.
 */
class Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
