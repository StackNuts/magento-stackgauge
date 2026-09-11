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
     * Site/store health: is something broken or about to be.
     */
    public const SECTION_HEALTH = 'health';

    /**
     * Data-layer infrastructure: databases, queues, indexers, and their state.
     */
    public const SECTION_DATA = 'data';

    /**
     * Store/merchandising data: sales, catalog, customers, promotions.
     */
    public const SECTION_COMMERCE = 'commerce';

    /**
     * Everything else about the platform itself: edition/version, installed code, security
     * posture, site structure - and the catch-all for anything that doesn't fit the other
     * three.
     */
    public const SECTION_PLATFORM = 'platform';

    /**
     * @var list<string>
     */
    public const VALID_SECTIONS = [
        self::SECTION_HEALTH,
        self::SECTION_DATA,
        self::SECTION_COMMERCE,
        self::SECTION_PLATFORM,
    ];

    public function getSection(): string;
}
