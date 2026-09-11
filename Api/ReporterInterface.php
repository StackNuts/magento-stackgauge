<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

use StackNuts\StackGauge\Api\Section\SectionInterface;

/**
 * Extension point for third-party modules to contribute a named block to the StackGauge
 * status payload. Implement this in your own module and register it against the "reporters"
 * array argument on \StackNuts\StackGauge\Model\ReporterPool via your own di.xml.
 *
 * getStatus() must return every value wrapped in a typed Section (see Api\Section\Section) -
 * Section::facts() for a flat key/value fact list, or Section::table() for a homogeneous
 * list of records. A reporter that returns anything other than a Section (or throws while
 * building one) has its whole block replaced with an {"error": ...} marker by ReporterPool,
 * so a bad reporter degrades gracefully rather than corrupting the payload.
 *
 * A reporter's field keys must stay unique across its own sections - the dashboard's
 * trackable-metric_key convention ("<reporter_name>.<field_key>") only sees the field key,
 * not which section it lives in.
 *
 * Do not use the keys "schema_version", "label", or "description" in the array returned by
 * getStatus() - ReporterPool wraps the array under those reserved keys itself.
 */
interface ReporterInterface
{
    /**
     * Payload key this reporter contributes under, e.g. "cloudflare". Must be unique across
     * every registered reporter.
     */
    public function getName(): string;

    /**
     * Short human-readable name for this reporter, e.g. "Redis" - shown as a heading on the
     * dashboard so a third-party reporter's block is self-explanatory, not just a raw key.
     */
    public function getLabel(): string;

    /**
     * One or two sentences on what this reporter covers, e.g. "Reachability and version of
     * Redis-backed cache and session backends." Shown alongside getLabel() on the dashboard.
     */
    public function getDescription(): string;

    /**
     * This reporter's own schema version, opaque to StackGauge - only the dashboard
     * interprets it. Lets a third-party reporter evolve its own shape independently of the
     * core module's payload schema_version. Bump this whenever a field's name, type, or
     * meaning changes - self-describing Fields mean the dashboard's generic rendering
     * doesn't need this to display an unfamiliar field safely, but any version-aware logic
     * (a specific hand-built view, a future health-rollup rule) still does.
     */
    public function getSchemaVersion(): string;

    /**
     * @return array<string, SectionInterface>
     */
    public function getStatus(): array;
}
