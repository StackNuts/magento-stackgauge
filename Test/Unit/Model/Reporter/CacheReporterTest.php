<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use StackNuts\StackGauge\Model\Reporter\CacheReporter;

class CacheReporterTest extends TestCase
{
    private function reporter(array $types, array $invalidated = []): CacheReporter
    {
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn($types);
        $typeList->method('getInvalidated')->willReturn($invalidated);

        $pageCacheConfig = $this->createMock(PageCacheConfig::class);
        $pageCacheConfig->method('getType')->willReturn(PageCacheConfig::BUILT_IN);
        $pageCacheConfig->method('isEnabled')->willReturn(true);

        return new CacheReporter($typeList, $pageCacheConfig);
    }

    public function testFullPageCacheComesBeforeTypes(): void
    {
        $status = $this->reporter(['config' => ['status' => 1]])->getStatus();

        $this->assertSame(['full_page_cache', 'types'], array_keys($status));
    }

    /**
     * @return array<string, array<string, \StackNuts\StackGauge\Api\Field\FieldInterface>>
     */
    private function rowsById(CacheReporter $reporter): array
    {
        $byId = [];
        foreach ($reporter->getStatus()['types']->getRows() as $row) {
            $fields = $row->getValue();
            $byId[$fields['id']->getValue()] = $fields;
        }

        return $byId;
    }

    public function testCacheTypeReportsNameDescriptionTagsWithoutDuplicatingId(): void
    {
        $rows = $this->rowsById($this->reporter([
            'config' => [
                'status' => 1,
                'cache_type' => 'Configuration',
                'description' => 'Configuration files merged data',
                'tags' => 'CONFIG',
            ],
        ]));

        $config = $rows['config'];

        $this->assertSame('config', $config['id']->getValue());
        $this->assertSame('Configuration', $config['name']->getValue());
        $this->assertSame('Configuration files merged data', $config['description']->getValue());
        $this->assertSame('CONFIG', $config['tags']->getValue());
    }

    public function testAnEnabledTypeReportsStatusEnabled(): void
    {
        $rows = $this->rowsById($this->reporter(['config' => ['status' => 1]]));

        $this->assertSame('Enabled', $rows['config']['status']->getValue());
    }

    public function testADisabledTypeReportsStatusDisabledAsCritical(): void
    {
        $rows = $this->rowsById($this->reporter(['layout' => ['status' => 0]]));

        $this->assertSame('Disabled', $rows['layout']['status']->getValue());
        $this->assertSame(
            ['Disabled', 'Invalidated'],
            $rows['layout']['status']->jsonSerialize()['critical_values']
        );
    }

    public function testAnInvalidatedTypeReportsStatusInvalidatedEvenThoughEnabled(): void
    {
        $rows = $this->rowsById($this->reporter(
            ['full_page' => ['status' => 1]],
            invalidated: ['full_page' => ['status' => 1]]
        ));

        $this->assertSame('Invalidated', $rows['full_page']['status']->getValue());
    }

    public function testAnUnrecognisedTypeIdIsReportedAsCustomWithTheRawIdStillAvailable(): void
    {
        // 3 = StackNuts\CloudflareCache\Model\Config::TYPE_CLOUDFLARE, a real FPC type this
        // reporter has no built-in label for, so it falls back to "custom" while the raw
        // id stays available via type_id.
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn([]);
        $typeList->method('getInvalidated')->willReturn([]);

        $pageCacheConfig = $this->createMock(PageCacheConfig::class);
        $pageCacheConfig->method('getType')->willReturn(3);
        $pageCacheConfig->method('isEnabled')->willReturn(true);

        $reporter = new CacheReporter($typeList, $pageCacheConfig);
        $fpc = $reporter->getStatus()['full_page_cache']->getFields();

        $this->assertSame('custom', $fpc['type_label']->getValue());
        $this->assertSame(3, $fpc['type_id']->getValue());
    }
}
