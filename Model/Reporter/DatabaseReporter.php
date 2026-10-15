<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;
use Throwable;

/**
 * MySQL/MariaDB version and connection health via a single SELECT VERSION().
 */
class DatabaseReporter implements ReporterInterface, DeclaresSectionInterface, DeclaresSubjectInterface, DeclaresSlotInterface
{
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param ResourceConnection $resourceConnection
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the database reporter.
     */
    public function getName(): string
    {
        return 'database';
    }

    /**
     * Human-readable label for the database reporter block.
     */
    public function getLabel(): string
    {
        return 'Database';
    }

    /**
     * One-line summary of what the database reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'MySQL/MariaDB version and reachability.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports connection reachability, version string, and distribution (mysql or mariadb) via SELECT VERSION().
     */
    public function getStatus(): array
    {
        try {
            $versionString = (string)$this->resourceConnection->getConnection()->fetchOne('SELECT VERSION()');

            return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
                'reachable' => $this->field->bool('Reachable', true, criticalWhen: false),
                'version' => $this->field->varchar('Version', $versionString),
                'distribution' => $this->field->varchar(
                    'Distribution',
                    stripos($versionString, 'mariadb') !== false ? 'mariadb' : 'mysql'
                ),
            ])];
        } catch (Throwable) {
            return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
                'reachable' => $this->field->bool('Reachable', false, criticalWhen: false),
                'version' => $this->field->varchar('Version', ''),
                'distribution' => $this->field->varchar('Distribution', ''),
            ])];
        }
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'database';
    }
}
