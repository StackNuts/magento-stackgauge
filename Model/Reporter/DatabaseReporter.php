<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;
use Throwable;

/**
 * MySQL/MariaDB version and connection health via a single SELECT VERSION().
 */
class DatabaseReporter implements ReporterInterface, DeclaresSectionInterface
{
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'database';
    }

    public function getLabel(): string
    {
        return 'Database';
    }

    public function getDescription(): string
    {
        return 'MySQL/MariaDB version and reachability.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        try {
            $versionString = (string)$this->resourceConnection->getConnection()->fetchOne('SELECT VERSION()');

            return ['general' => Section::facts('general', 'General', $this->getDescription(), [
                'reachable' => Field::bool('Reachable', true, criticalWhen: false),
                'version' => Field::varchar('Version', $versionString),
                'distribution' => Field::varchar(
                    'Distribution',
                    stripos($versionString, 'mariadb') !== false ? 'mariadb' : 'mysql'
                ),
            ])];
        } catch (Throwable) {
            return ['general' => Section::facts('general', 'General', $this->getDescription(), [
                'reachable' => Field::bool('Reachable', false, criticalWhen: false),
                'version' => Field::varchar('Version', ''),
                'distribution' => Field::varchar('Distribution', ''),
            ])];
        }
    }
}
