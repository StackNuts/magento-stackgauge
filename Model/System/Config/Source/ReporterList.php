<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for the admin "Enabled Reporters" multiselect. Lists only this module's own
 * built-in reporter codes (the "name" each Reporter\* class returns) - third-party
 * reporters registered via di.xml are always-on and not offered here, per the scope note
 * in Api\ReporterInterface.
 */
class ReporterList implements OptionSourceInterface
{
    /**
     * Canonical list of built-in reporter codes - also consumed by Model\ReporterPool to
     * decide which reporter names are subject to the admin toggle at all (a third-party
     * reporter's name won't appear here, so it stays always-on regardless of this setting).
     */
    public const CODES = [
        'core',
        'modules',
        'composer',
        'db_schema',
        'patches',
        'inventory',
        'customers',
        'abandoned_carts',
        'indexers',
        'cron',
        'cache',
        'security',
        'redis',
        'search',
        'rabbitmq',
        'disk',
        'database',
        'sales',
        'catalog',
        'coupons',
        'logs',
        'reports',
        'store_views',
        'themes',
        'payments',
        'php_extensions',
        'product_health',
    ];

    public function toOptionArray(): array
    {
        return [
            ['value' => 'core', 'label' => __('Core (edition, version, PHP, deploy mode)')],
            ['value' => 'modules', 'label' => __('Modules')],
            ['value' => 'composer', 'label' => __('Composer')],
            ['value' => 'db_schema', 'label' => __('DB Schema Drift')],
            ['value' => 'inventory', 'label' => __('Inventory (stock levels)')],
            ['value' => 'customers', 'label' => __('Customers / Signals')],
            ['value' => 'abandoned_carts', 'label' => __('Abandoned carts')],
            ['value' => 'patches', 'label' => __('Patches')],
            ['value' => 'indexers', 'label' => __('Indexers')],
            ['value' => 'cron', 'label' => __('Cron')],
            ['value' => 'cache', 'label' => __('Cache (incl. Full Page Cache type)')],
            ['value' => 'security', 'label' => __('Security')],
            ['value' => 'redis', 'label' => __('Redis (cache + session backends)')],
            ['value' => 'search', 'label' => __('Search engine (Elasticsearch/OpenSearch)')],
            ['value' => 'rabbitmq', 'label' => __('RabbitMQ (per-queue depth, if configured)')],
            ['value' => 'disk', 'label' => __('Disk space (var/log, var/cache, media)')],
            ['value' => 'database', 'label' => __('Database (MySQL/MariaDB version)')],
            ['value' => 'sales', 'label' => __('Sales (lifetime order/quote counts, hourly breakdown)')],
            ['value' => 'catalog', 'label' => __('Catalog (enabled product count)')],
            ['value' => 'coupons', 'label' => __('Coupons (active cart price rules, redemption totals)')],
            ['value' => 'logs', 'label' => __('Logs (recent exceptions)')],
            ['value' => 'reports', 'label' => __('Reports (recent StackGauge report history)')],
            ['value' => 'store_views', 'label' => __('Store Views')],
            ['value' => 'themes', 'label' => __('Themes (active frontend/admin theme)')],
            ['value' => 'payments', 'label' => __('Payment Methods')],
            ['value' => 'php_extensions', 'label' => __('PHP Extensions & Settings')],
            ['value' => 'product_health', 'label' => __('Product Health (missing image/price issues)')],
        ];
    }
}
