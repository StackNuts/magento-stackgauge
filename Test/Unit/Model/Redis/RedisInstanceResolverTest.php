<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Redis;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Redis\RedisInstanceResolver;

class RedisInstanceResolverTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function backendForms(): array
    {
        return [
            'short name' => ['redis'],
            'magento class' => ['Magento\\Framework\\Cache\\Backend\\Redis'],
            'leading backslash' => ['\\Magento\\Framework\\Cache\\Backend\\Redis'],
            'cm class' => ['Cm_Cache_Backend_Redis'],
            'valkey' => ['Magento\\Framework\\Cache\\Backend\\Valkey'],
        ];
    }

    #[DataProvider('backendForms')]
    public function testDetectsEveryDirectBackendForm(string $backend): void
    {
        $resolver = $this->resolver(['cache' => ['frontend' => [
            'default' => ['backend' => $backend, 'backend_options' => ['server' => 'cache-host', 'port' => '6380', 'database' => '3', 'password' => 'pw']],
        ]]]);

        $instances = $resolver->resolve();

        $this->assertCount(1, $instances);
        $this->assertSame(['cache_default'], $instances[0]->roles);
        $this->assertSame('cache-host', $instances[0]->host);
        $this->assertSame(6380, $instances[0]->port);
        $this->assertSame(3, $instances[0]->database);
        $this->assertSame('pw', $instances[0]->password);
        $this->assertNull($instances[0]->username);
        $this->assertSame($backend, $resolver->defaultCacheBackend());
    }

    public function testUnwrapsL2RemoteSynchronizedCache(): void
    {
        $resolver = $this->resolver(['cache' => ['frontend' => [
            'default' => [
                'backend' => '\\Magento\\Framework\\Cache\\Backend\\RemoteSynchronizedCache',
                'backend_options' => [
                    'remote_backend' => '\\Magento\\Framework\\Cache\\Backend\\Redis',
                    'remote_backend_options' => ['server' => 'l2-host', 'database' => '4', 'username' => 'acl', 'password' => 'x'],
                    'local_backend' => 'Cm_Cache_Backend_File',
                ],
            ],
        ]]]);

        $instances = $resolver->resolve();

        $this->assertCount(1, $instances);
        $this->assertSame('l2-host', $instances[0]->host);
        $this->assertSame(6379, $instances[0]->port);
        $this->assertSame(4, $instances[0]->database);
        $this->assertSame('acl', $instances[0]->username);
        $this->assertSame('\\Magento\\Framework\\Cache\\Backend\\Redis', $resolver->defaultCacheBackend());
    }

    public function testL2WithFileRemoteIsNotRedis(): void
    {
        $resolver = $this->resolver(['cache' => ['frontend' => ['default' => [
            'backend' => 'Magento\\Framework\\Cache\\Backend\\RemoteSynchronizedCache',
            'backend_options' => ['remote_backend' => 'Cm_Cache_Backend_File', 'remote_backend_options' => ['cache_dir' => '/x']],
        ]]]]);

        $this->assertSame([], $resolver->resolve());
        $this->assertSame('Cm_Cache_Backend_File', $resolver->defaultCacheBackend());
    }

    public function testValkeySessionsUseTheirOwnOptionsBlock(): void
    {
        $resolver = $this->resolver(['session' => [
            'save' => 'valkey',
            'valkey' => ['host' => 'sess', 'port' => '6381', 'database' => '2', 'timeout' => '1.5'],
        ]]);

        $instances = $resolver->resolve();

        $this->assertCount(1, $instances);
        $this->assertSame(['session'], $instances[0]->roles);
        $this->assertSame('sess', $instances[0]->host);
        $this->assertSame(6381, $instances[0]->port);
        $this->assertSame(1.5, $instances[0]->timeout);
    }

    public function testRedisSessionsAndDeduplicationMergeRoles(): void
    {
        $opts = static fn (string $db): array => ['server' => 'redis', 'port' => '6379', 'database' => $db];
        $resolver = $this->resolver([
            'cache' => ['frontend' => [
                'default' => ['backend' => 'redis', 'backend_options' => $opts('0')],
                'page_cache' => ['backend' => 'redis', 'backend_options' => $opts('1')],
            ]],
            'session' => ['save' => 'redis', 'redis' => ['host' => 'REDIS', 'database' => '1']],
        ]);

        $instances = $resolver->resolve();

        $this->assertCount(2, $instances);
        $this->assertSame('cache_default', $instances[0]->name());
        $this->assertSame('cache_page_cache+session', $instances[1]->name());
    }

    public function testIgnoresNonRedisBackendsAndFileSessions(): void
    {
        $resolver = $this->resolver([
            'cache' => ['frontend' => ['default' => ['backend' => 'Cm_Cache_Backend_File', 'backend_options' => ['server' => 'x']]]],
            'session' => ['save' => 'files'],
        ]);

        $this->assertSame([], $resolver->resolve());
        $this->assertSame([], $this->resolver([])->resolve());
        $this->assertSame('', $this->resolver([])->defaultCacheBackend());
    }

    public function testUnixSocketHasNoPort(): void
    {
        $resolver = $this->resolver(['cache' => ['frontend' => ['default' => [
            'backend' => 'redis', 'backend_options' => ['server' => '/var/run/redis.sock', 'port' => '6379'],
        ]]]]);

        $this->assertSame(0, $resolver->resolve()[0]->port);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function resolver(array $config): RedisInstanceResolver
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

        return new RedisInstanceResolver($deployment);
    }
}
