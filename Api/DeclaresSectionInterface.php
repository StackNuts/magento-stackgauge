<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

/**
 * Optional companion to ReporterInterface, letting a reporter declare which broad domain
 * category its data belongs to, so a dashboard can group reporters without hardcoding a
 * lookup of every reporter name it knows about.
 *
 * These are broad, stable domain categories ("what kind of data is this"), not literal UI
 * section names - how a category is labelled, ordered, or rendered is entirely a
 * dashboard-side decision.
 *
 * A reporter that doesn't implement this interface, or returns something outside
 * VALID_SECTIONS, simply has no section - ReporterPool omits the "section" key entirely
 * rather than failing the reporter.
 */
interface DeclaresSectionInterface
{
    /**
     * Site/store health: is it working right now (uptime/maintenance, cron, cache, indexers,
     * disk, logs, reports).
     */
    public const SECTION_HEALTH = 'health';

    /**
     * Infrastructure services: database, search, queues, Redis, PHP extensions, and their state.
     * (The key stays "data"; dashboards label it "Infrastructure".)
     */
    public const SECTION_DATA = 'data';

    /**
     * Store/merchandising data: sales, catalog, customers, promotions.
     */
    public const SECTION_COMMERCE = 'commerce';

    /**
     * Everything else about the platform itself: edition/version, installed code, site
     * structure - and the catch-all for anything that doesn't fit the other categories.
     */
    public const SECTION_PLATFORM = 'platform';

    /**
     * Admin/account hygiene, config hygiene, filesystem exposure, and content-signature
     * (webshell/Magecart) findings - security posture specifically, not general platform
     * state. Used by StackNuts_StackGaugeSecurity's reporters.
     */
    public const SECTION_SECURITY = 'security';

    /**
     * Runtime and configuration signals that affect how fast the store responds: PHP/MySQL/Redis
     * runtime health, cron and indexer duration, and caching configuration. Used by
     * StackNuts_StackGaugePerformance's reporters.
     */
    public const SECTION_PERFORMANCE = 'performance';

    /**
     * @var list<string>
     */
    public const VALID_SECTIONS = [
        self::SECTION_HEALTH,
        self::SECTION_DATA,
        self::SECTION_COMMERCE,
        self::SECTION_PLATFORM,
        self::SECTION_SECURITY,
        self::SECTION_PERFORMANCE,
    ];

    /**
     * One of self::SECTION_* (see VALID_SECTIONS) - the domain category this reporter's data belongs to.
     */
    public function getSection(): string;
}
