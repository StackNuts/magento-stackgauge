<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use DateTimeImmutable;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

/**
 * The top-level fields every payload sent to the dashboard starts with - full report, config
 * sync and heartbeat alike: type, schema_version, generated_at (which the dashboard requires
 * to be recent - it's part of its replay protection) and the site identifier, plus this
 * module's own installed version where the payload carries it.
 *
 * Separate from PayloadBuilder so HeartbeatSender can use it without pulling in ReporterPool
 * (and with it every reporter's dependencies) on its five-minute path.
 */
class PayloadEnvelope
{
    private const PACKAGE_NAME = 'stacknuts/magento-stackgauge';

    /**
     * @param Config $config
     * @param ComposerLockReader $composerLockReader
     */
    public function __construct(
        private readonly Config $config,
        private readonly ComposerLockReader $composerLockReader
    ) {
    }

    /**
     * The envelope for a $type payload at $schemaVersion. module_version reads composer.lock,
     * so it's opt-in - the heartbeat leaves it out.
     *
     * @param string $type
     * @param string $schemaVersion
     * @param bool $withModuleVersion
     * @return array<string, mixed>
     */
    public function create(string $type, string $schemaVersion, bool $withModuleVersion = true): array
    {
        $envelope = [
            'type' => $type,
            'schema_version' => $schemaVersion,
        ];

        if ($withModuleVersion) {
            $envelope['module_version'] = $this->getModuleVersion();
        }

        return $envelope + [
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
        ];
    }

    /**
     * This module's installed version from composer.lock, or null if it isn't there (e.g.
     * installed under app/code rather than via Composer).
     */
    private function getModuleVersion(): ?string
    {
        $decoded = $this->composerLockReader->getDecoded();
        $packages = is_array($decoded) && is_array($decoded['packages'] ?? null) ? $decoded['packages'] : [];

        foreach ($packages as $package) {
            if (($package['name'] ?? null) === self::PACKAGE_NAME) {
                return is_string($package['version'] ?? null) ? $package['version'] : null;
            }
        }

        return null;
    }
}
