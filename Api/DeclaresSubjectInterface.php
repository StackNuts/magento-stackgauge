<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

/**
 * Optional companion to ReporterInterface, letting a reporter name the thing it reports on
 * ("cron", "redis", "database", ...) so a dashboard can cross-link reporters that describe the
 * same subject from different angles (e.g. cron's "is it running" and cron_perf's "how long
 * does it take") even though they live in different categories.
 *
 * The value is a free-form lowercase slug: reporters sharing a subject are related, nothing
 * more. A reporter that doesn't implement this, or returns an empty value, simply has no
 * subject - ReporterPool omits the "subject" key entirely.
 */
interface DeclaresSubjectInterface
{
    /**
     * Lowercase slug naming what this reporter reports on, e.g. "redis".
     */
    public function getSubject(): string;
}
