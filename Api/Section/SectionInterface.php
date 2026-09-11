<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Section;

use JsonSerializable;

/**
 * One named, described, shape-constrained block within a reporter's getStatus() - the unit
 * the dashboard renders directly (a table or a small key/value fact list) without needing to
 * sniff what's inside it. Construct instances via the Section factory (Section::facts(),
 * Section::table()) rather than the concrete classes directly.
 */
interface SectionInterface extends JsonSerializable
{
    /**
     * Stable key within this reporter, e.g. "general", "jobs" - must match the key this
     * section is registered under in ReporterInterface::getStatus()'s returned array.
     */
    public function getKey(): string;

    /**
     * One of Section::KIND_* - stable, dashboard-facing shape identifier.
     */
    public function getKind(): string;

    /**
     * Short heading, e.g. "Jobs".
     */
    public function getLabel(): string;

    /**
     * One sentence on what this section covers. May be empty.
     */
    public function getDescription(): string;
}
