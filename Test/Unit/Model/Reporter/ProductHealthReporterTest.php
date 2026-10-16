<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\ProductHealth\ProductHealthQueries;
use StackNuts\StackGauge\Model\Reporter\ProductHealthReporter;

class ProductHealthReporterTest extends TestCase
{
    /** @var list<string> */
    private array $sql = [];

    /**
     * @param array<int,array<string,mixed>> $sampleRows
     */
    private function reporter(string $linkField, string|int $count, array $sampleRows = []): ProductHealthReporter
    {
        $this->sql = [];
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $connection->method('fetchPairs')->willReturn(['status' => '97', 'visibility' => '99', 'image' => '87']);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use ($count) {
            $this->sql[] = $sql;
            return $count;
        });
        $connection->method('fetchAll')->willReturnCallback(function (string $sql) use ($sampleRows) {
            $this->sql[] = $sql;
            return $sampleRows;
        });

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        return new ProductHealthReporter(
            new ProductHealthQueries($resource, $pool),
            new Field(),
            new Section()
        );
    }

    public function testPayloadShapeAndCounts(): void
    {
        $status = $this->reporter('entity_id', '3', [
            ['sku' => 'A', 'name' => 'Alpha', 'type_id' => 'simple', 'extra' => 'No child products',
                'url_key' => 'a', 'products' => '2', 'skus' => 'A, B'],
        ])->getStatus();

        $this->assertSame('general', array_key_first($status));
        $fields = $status['general']->getFields();
        foreach (['missing_image', 'no_price', 'missing_description', 'missing_meta_description', 'no_website',
            'no_category', 'orphaned_not_visible', 'composite_unbuyable', 'url_rewrite_missing',
            'duplicate_url_key', 'configurable_out_of_stock'] as $key) {
            $this->assertArrayHasKey($key, $fields);
            $this->assertSame(3, $fields[$key]->getValue());
        }
        $this->assertArrayHasKey('missing_image_samples', $status);
        $this->assertArrayHasKey('composite_unbuyable_samples', $status);
        $this->assertArrayHasKey('configurable_out_of_stock_samples', $status);
        $this->assertArrayHasKey('duplicate_url_key_samples', $status);
        $this->assertArrayNotHasKey('missing_meta_description_samples', $status);
    }

    public function testMetricCatalog(): void
    {
        $metrics = [];
        foreach ($this->reporter('entity_id', 0)->getTrackableMetrics() as $m) {
            $metrics[$m->getMetricKey()] = $m;
        }
        $this->assertSame([
            'product_health.missing_image_count',
            'product_health.no_price_count',
            'product_health.no_website_count',
            'product_health.composite_unbuyable_count',
        ], array_keys($metrics));
        $this->assertArrayNotHasKey('product_health.configurable_out_of_stock_count', $metrics);
        $this->assertSame(20, $metrics['product_health.missing_image_count']->getDefaultThreshold());
        $this->assertSame(5, $metrics['product_health.no_price_count']->getDefaultThreshold());
        $this->assertSame(0, $metrics['product_health.no_website_count']->getDefaultThreshold());
        $this->assertSame(0, $metrics['product_health.composite_unbuyable_count']->getDefaultThreshold());
    }

    public function testEavJoinsUseLinkField(): void
    {
        $this->reporter('row_id', 0)->getStatus();
        $joined = implode("\n", $this->sql);
        $this->assertStringContainsString('v_status.row_id = p.row_id', $joined);
        $this->assertStringNotContainsString('v_status.entity_id', $joined);
        $this->assertStringContainsString('p.created_in', $joined);

        $this->reporter('entity_id', 0)->getStatus();
        $this->assertStringContainsString('v_status.entity_id = p.entity_id', implode("\n", $this->sql));
    }

    public function testEmptyCatalog(): void
    {
        $status = $this->reporter('entity_id', 0)->getStatus();
        foreach ($status['general']->getFields() as $field) {
            $this->assertSame(0, $field->getValue());
        }
        $this->assertCount(0, $status['no_price_samples']->getRows());
    }

    public function testDeclarations(): void
    {
        $r = $this->reporter('entity_id', 0);
        $this->assertSame('product_health', $r->getName());
        $this->assertSame('catalog', $r->getSubject());
        $this->assertSame(1, $r->getSlot());
    }
}
