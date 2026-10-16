<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Redis\RedisInstanceResolver;
use StackNuts\StackGauge\Model\Reporter\RedisReporter;

class RedisReporterTest extends TestCase
{
    public function testNoRedisConfiguredGivesEmptyTable(): void
    {
        $this->assertCount(0, $this->rows([]));
    }

    public function testUnreachableMagentoClassBackendIsReportedUnreachableWithoutHanging(): void
    {
        $rows = $this->rows(['cache' => ['frontend' => ['default' => [
            'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
            'backend_options' => ['server' => '127.0.0.1', 'port' => '1', 'database' => '0'],
        ]]]]);

        $this->assertCount(1, $rows);
        $fields = $rows[0]->getValue();
        $this->assertSame('cache_default', $fields['name']->getValue());
        $this->assertSame('Cache Frontend', $fields['purpose']->getValue());
        $this->assertFalse($fields['reachable']->getValue());
    }

    /**
     * @param array<string,mixed> $config
     * @return list<\StackNuts\StackGauge\Api\Field\ArrayField>
     */
    private function rows(array $config): array
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(static function (string $path, $default = null) use ($config) {
            $value = $config;
            foreach (explode('/', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }

            return $value;
        });
        $status = (new RedisReporter(new RedisInstanceResolver($deployment), new Field(), new Section()))->getStatus();

        return $status['backends']->getRows();
    }
}
