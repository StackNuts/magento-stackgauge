<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Credis_Client;
use Magento\Framework\App\DeploymentConfig;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use Throwable;

/**
 * Reachability + version for every Redis-backed cache frontend and the session backend,
 * checked separately since cache and session can be entirely different Redis instances.
 * Uses Credis_Client rather than the phpredis extension directly, since Credis is already a
 * transitive dependency of magento/framework and present on every real Magento install
 * regardless of whether phpredis is compiled in.
 */
class RedisReporter implements ReporterInterface, DeclaresSectionInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * A slow/unreachable Redis must not stall the whole report - this is collection-time
     * I/O, not the final HTTP send, so the same "never hang" discipline applies here too.
     */
    private const CONNECT_TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function getName(): string
    {
        return 'redis';
    }

    public function getLabel(): string
    {
        return 'Redis';
    }

    public function getDescription(): string
    {
        return 'Reachability and version of Redis-backed cache and session backends, checked separately.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $backends = [];

        foreach ((array)$this->deploymentConfig->get('cache/frontend', []) as $frontendId => $frontend) {
            if (($frontend['backend'] ?? null) === 'redis') {
                $backends[] = $this->checkBackend(
                    'cache_' . $frontendId,
                    'Cache Frontend',
                    (array)($frontend['backend_options'] ?? [])
                );
            }
        }

        $session = (array)$this->deploymentConfig->get('session', []);
        if (($session['save'] ?? null) === 'redis') {
            $backends[] = $this->checkBackend('session', 'Session Store', (array)($session['redis'] ?? []));
        }

        return ['backends' => Section::table('backends', 'Backend Types', $this->getDescription(), $backends)];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function checkBackend(string $name, string $purpose, array $options): ArrayField
    {
        $host = (string)($options['server'] ?? $options['host'] ?? '');
        $port = (int)($options['port'] ?? 6379);
        $database = (int)($options['database'] ?? 0);
        $password = $options['password'] ?? null;

        if ($host === '') {
            return $this->backendField($name, $purpose, false, null);
        }

        try {
            $client = new Credis_Client($host, $port, self::CONNECT_TIMEOUT_SECONDS, '', $database, $password ?: null);
            $client->setMaxConnectRetries(0);
            $info = $client->info();

            return $this->backendField($name, $purpose, true, $info['redis_version'] ?? null);
        } catch (Throwable) {
            return $this->backendField($name, $purpose, false, null);
        }
    }

    private function backendField(string $name, string $purpose, bool $reachable, ?string $version): ArrayField
    {
        return Field::array($name, [
            'name' => Field::varchar('Name', $name),
            'purpose' => Field::varchar('Purpose', $purpose),
            'reachable' => Field::bool('Reachable', $reachable, criticalWhen: false),
            'version' => Field::varchar('Version', $version ?? ''),
        ]);
    }
}
