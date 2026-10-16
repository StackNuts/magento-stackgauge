<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Redis;

/**
 * One distinct Redis/Valkey endpoint (host:port:database) found in env.php, with every role that uses it.
 */
class RedisInstance
{
    /**
     * @param list<string> $roles e.g. cache_default, cache_page_cache, session
     * @param string $host
     * @param int $port 0 for a unix socket
     * @param int $database
     * @param string|null $password
     * @param string|null $username Redis 6 ACL user
     * @param float $timeout connect timeout in seconds
     */
    public function __construct(
        public readonly array $roles,
        public readonly string $host,
        public readonly int $port,
        public readonly int $database,
        public readonly ?string $password,
        public readonly ?string $username,
        public readonly float $timeout
    ) {
    }

    /**
     * Readable name built from the roles, e.g. "cache_default+cache_page_cache".
     */
    public function name(): string
    {
        return implode('+', $this->roles);
    }

    /**
     * Instance-level key used for INFO, which is server-wide and so ignores the database number.
     */
    public function serverKey(): string
    {
        return $this->host . ':' . $this->port;
    }
}
