<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Credis_Client;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Redis\RedisInstance;
use StackNuts\StackGauge\Model\Redis\RedisInstanceResolver;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;
use Throwable;

/**
 * Reachability + version for every Redis-backed cache frontend and the session backend,
 * checked separately since cache and session can be entirely different Redis instances.
 * Uses Credis_Client rather than the phpredis extension directly, since Credis is already a
 * transitive dependency of magento/framework and present on every real Magento install
 * regardless of whether phpredis is compiled in.
 */
class RedisReporter implements ReporterInterface, DeclaresSectionInterface, DeclaresSubjectInterface
{
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * A slow/unreachable Redis must not stall the whole report - this is collection-time
     * I/O, not the final HTTP send, so the same "never hang" discipline applies here too.
     */
    private const CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * A stalled INFO would otherwise block for default_socket_timeout (60s).
     */
    private const READ_TIMEOUT_SECONDS = 3.0;

    /**
     * @param RedisInstanceResolver $resolver
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly RedisInstanceResolver $resolver,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the Redis reporter.
     */
    public function getName(): string
    {
        return 'redis';
    }

    /**
     * Human-readable label for the Redis reporter block.
     */
    public function getLabel(): string
    {
        return 'Redis';
    }

    /**
     * One-line summary of what the Redis reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Reachability and version of Redis-backed cache and session backends, checked separately.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports reachability and version for each distinct Redis/Valkey instance behind the cache frontends and
     * sessions, via Credis_Client. An instance shared by several roles is checked once.
     */
    public function getStatus(): array
    {
        $backends = [];
        foreach ($this->resolver->resolve() as $instance) {
            $backends[] = $this->checkBackend($instance);
        }

        return [
            'backends' => $this->section->table('backends', 'Backend Types', $this->getDescription(), $backends),
        ];
    }

    /**
     * Connects to one Redis instance with short connect and read timeouts and builds its reachability/version row.
     *
     * @param RedisInstance $instance
     */
    private function checkBackend(RedisInstance $instance): ArrayField
    {
        $purpose = $this->purpose($instance);

        try {
            $client = new Credis_Client(
                $instance->host,
                $instance->port,
                min($instance->timeout, self::CONNECT_TIMEOUT_SECONDS),
                '',
                $instance->database,
                $instance->password,
                $instance->username
            );
            $client->setMaxConnectRetries(0);
            $client->setReadTimeout(self::READ_TIMEOUT_SECONDS);
            $info = $client->info();

            return $this->backendField($instance->name(), $purpose, true, $info['redis_version'] ?? null);
        } catch (Throwable) {
            return $this->backendField($instance->name(), $purpose, false, null);
        }
    }

    /**
     * Human-readable purpose of an instance from its roles.
     *
     * @param RedisInstance $instance
     */
    private function purpose(RedisInstance $instance): string
    {
        $labels = [];
        foreach ($instance->roles as $role) {
            $labels[] = $role === 'session' ? 'Session Store' : 'Cache Frontend';
        }

        return implode(' + ', array_unique($labels));
    }

    /**
     * Builds one backend's row.
     *
     * @param string $name
     * @param string $purpose
     * @param bool $reachable
     * @param string|null $version
     */
    private function backendField(string $name, string $purpose, bool $reachable, ?string $version): ArrayField
    {
        return $this->field->array($name, [
            'name' => $this->field->varchar('Name', $name),
            'purpose' => $this->field->varchar('Purpose', $purpose),
            'reachable' => $this->field->bool('Reachable', $reachable, criticalWhen: false),
            'version' => $this->field->varchar('Version', $version ?? ''),
        ]);
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'redis';
    }
}
