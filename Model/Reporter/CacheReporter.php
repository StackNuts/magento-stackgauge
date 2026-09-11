<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Field\FieldInterface;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;

class CacheReporter implements ReporterInterface, DeclaresSectionInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    private const STATUS_ENABLED = 'Enabled';
    private const STATUS_DISABLED = 'Disabled';
    private const STATUS_INVALIDATED = 'Invalidated';

    /**
     * Both count as critical: a disabled type serves nothing, an invalidated one is serving
     * stale data until the next cache:flush.
     *
     * @var list<string>
     */
    private const CRITICAL_STATUSES = [self::STATUS_DISABLED, self::STATUS_INVALIDATED];

    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly PageCacheConfig $pageCacheConfig
    ) {
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function getLabel(): string
    {
        return 'Cache';
    }

    public function getDescription(): string
    {
        return 'Per-cache-type name/description/tags/status, plus which Full Page Cache type is active.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $types = [];
        $invalidated = $this->cacheTypeList->getInvalidated();

        foreach ($this->cacheTypeList->getTypes() as $id => $info) {
            $status = match (true) {
                isset($invalidated[$id]) => self::STATUS_INVALIDATED,
                (bool)($info['status'] ?? 0) => self::STATUS_ENABLED,
                default => self::STATUS_DISABLED,
            };

            $types[] = Field::array((string)$id, [
                'id' => Field::varchar('ID', (string)$id),
                'name' => Field::varchar('Name', (string)($info['cache_type'] ?? $id)),
                'description' => Field::varchar('Description', (string)($info['description'] ?? '')),
                'tags' => Field::varchar('Tags', (string)($info['tags'] ?? '')),
                'status' => Field::varchar('Status', $status, self::CRITICAL_STATUSES),
            ]);
        }

        return [
            'full_page_cache' => Section::facts(
                'full_page_cache',
                'Full Page Cache',
                'Which Full Page Cache type is active.',
                $this->getFullPageCacheStatus()
            ),
            'types' => Section::table('types', 'Cache Types', '', $types, keyName: 'id'),
        ];
    }

    /**
     * A third-party module can register additional Full Page Cache types beyond built_in/
     * varnish; any type_id we don't recognize is reported as "custom", with the raw type_id
     * still available via the sibling field.
     *
     * @return array<string, FieldInterface>
     */
    private function getFullPageCacheStatus(): array
    {
        $typeId = (int)$this->pageCacheConfig->getType();

        $typeLabel = match ($typeId) {
            PageCacheConfig::BUILT_IN => 'built_in',
            PageCacheConfig::VARNISH => 'varnish',
            default => 'custom',
        };

        return [
            'enabled' => Field::bool('Enabled', $this->pageCacheConfig->isEnabled(), criticalWhen: false),
            'type_id' => Field::number('Type ID', $typeId),
            'type_label' => Field::varchar('Type Label', $typeLabel),
        ];
    }
}
