<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Redis;

use Magento\Framework\App\DeploymentConfig;

/**
 * Single source of truth for which Redis/Valkey instances this install uses, read from env.php.
 * Handles every backend spelling Magento writes ('redis', Magento\Framework\Cache\Backend\Redis,
 * Cm_Cache_Backend_Redis, Valkey) and the L2 RemoteSynchronizedCache wrapper, whose real backend
 * lives in remote_backend/remote_backend_options. Shared by the core and performance Redis reporters.
 */
class RedisInstanceResolver
{
    private const DEFAULT_PORT = 6379;
    private const DEFAULT_TIMEOUT = 2.0;
    private const WRAPPER_MARKER = 'remotesynchronizedcache';

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    /**
     * Distinct Redis instances used by cache frontends and sessions, deduplicated by host:port:database.
     *
     * @return list<RedisInstance>
     */
    public function resolve(): array
    {
        /** @var array<string, array{role: string, options: array<string,mixed>}> $found */
        $found = [];
        $add = function (string $role, array $options) use (&$found): void {
            $host = trim((string)($options['server'] ?? $options['host'] ?? ''));
            if ($host === '') {
                return;
            }
            $port = $this->isSocket($host) ? 0 : (int)(($options['port'] ?? '') !== '' ? $options['port'] : self::DEFAULT_PORT);
            $database = (int)($options['database'] ?? 0);
            $key = strtolower($host) . ':' . $port . ':' . $database;
            if (!isset($found[$key])) {
                $found[$key] = ['roles' => [], 'host' => $host, 'port' => $port, 'database' => $database,
                    'password' => null, 'username' => null, 'timeout' => self::DEFAULT_TIMEOUT];
            }
            $found[$key]['roles'][] = $role;
            $found[$key]['password'] ??= $this->nonEmpty($options['password'] ?? null);
            $found[$key]['username'] ??= $this->nonEmpty($options['username'] ?? $options['user'] ?? null);
            if (isset($options['timeout']) && is_numeric($options['timeout']) && (float)$options['timeout'] > 0) {
                $found[$key]['timeout'] = min(self::DEFAULT_TIMEOUT, (float)$options['timeout']);
            }
        };

        foreach ($this->frontends() as $id => $frontend) {
            [$backend, $options] = $this->unwrap($frontend);
            if (self::isRedisBackend($backend)) {
                $add('cache_' . $id, $options);
            }
        }

        $session = $this->deploymentConfig->get('session', []);
        $session = is_array($session) ? $session : [];
        $save = (string)($session['save'] ?? '');
        if (self::isRedisBackend($save)) {
            $options = $session[$save] ?? $session['redis'] ?? $session['valkey'] ?? [];
            $add('session', is_array($options) ? $options : []);
        }

        return array_values(array_map(
            static fn (array $i): RedisInstance => new RedisInstance(
                $i['roles'],
                $i['host'],
                $i['port'],
                $i['database'],
                $i['password'],
                $i['username'],
                $i['timeout']
            ),
            $found
        ));
    }

    /**
     * Real backend class/name behind the default cache frontend, after unwrapping an L2 wrapper. Empty if unset.
     */
    public function defaultCacheBackend(): string
    {
        $default = $this->frontends()['default'] ?? [];

        return $this->unwrap($default)[0];
    }

    /**
     * Whether a backend name (cache backend class or session save handler) is a Redis/Valkey one.
     *
     * @param string $backend
     */
    public static function isRedisBackend(string $backend): bool
    {
        $backend = strtolower($backend);

        return str_contains($backend, 'redis') || str_contains($backend, 'valkey');
    }

    /**
     * Cache frontends from env.php, as an id-keyed list of arrays.
     *
     * @return array<string, array<string,mixed>>
     */
    private function frontends(): array
    {
        $frontends = $this->deploymentConfig->get('cache/frontend', []);
        $out = [];
        foreach (is_array($frontends) ? $frontends : [] as $id => $frontend) {
            if (is_array($frontend)) {
                $out[(string)$id] = $frontend;
            }
        }

        return $out;
    }

    /**
     * The effective backend name and options of a frontend, looking through an L2 wrapper to its remote backend.
     *
     * @param array<string,mixed> $frontend
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function unwrap(array $frontend): array
    {
        $backend = (string)($frontend['backend'] ?? '');
        $options = is_array($frontend['backend_options'] ?? null) ? $frontend['backend_options'] : [];
        if (str_contains(strtolower($backend), self::WRAPPER_MARKER)) {
            $backend = (string)($options['remote_backend'] ?? '');
            $options = is_array($options['remote_backend_options'] ?? null) ? $options['remote_backend_options'] : [];
        }

        return [$backend, $options];
    }

    /**
     * @param string $host
     */
    private function isSocket(string $host): bool
    {
        return str_starts_with($host, '/') || str_starts_with($host, 'unix:');
    }

    /**
     * @param mixed $value
     */
    private function nonEmpty(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string)$value : '';

        return $value !== '' ? $value : null;
    }
}
