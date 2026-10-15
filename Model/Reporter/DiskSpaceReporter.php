<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use Throwable;

/**
 * Free/total disk space for var/log, var/cache, and media. Often the same underlying
 * filesystem on a typical install, but not guaranteed - media in particular is sometimes on
 * a separate volume - so each is checked independently.
 */
class DiskSpaceReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface, DeclaresSlotInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_MEDIA_FREE_PERCENT = 'disk.media.free_percent';

    /**
     * Below this, free space is critical - matches the trackable metric's own default
     * threshold below, applied uniformly across all three volumes for a consistent reading
     * even though only media has a real tunable AlertRule today.
     */
    private const FREE_PERCENT_CRITICAL_THRESHOLD = 10;

    /**
     * @var array<string, string>
     */
    private const DIRECTORIES = [
        'var_log' => DirectoryList::LOG,
        'var_cache' => DirectoryList::CACHE,
        'media' => DirectoryList::MEDIA,
    ];

    /**
     * @var array<string, string>
     */
    private const PURPOSES = [
        'var_log' => 'Logs',
        'var_cache' => 'Cache',
        'media' => 'Media',
    ];

    /**
     * @param Filesystem $filesystem
     * @param File $filesystemDriver
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly File $filesystemDriver,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the disk-space reporter.
     */
    public function getName(): string
    {
        return 'disk';
    }

    /**
     * Human-readable label for the disk-space reporter block.
     */
    public function getLabel(): string
    {
        return 'Disk Space';
    }

    /**
     * One-line summary of what the disk-space reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Free/total bytes for var/log, var/cache, and media, checked independently.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports free/total bytes and free percent for the var/log, var/cache, and media volumes.
     */
    public function getStatus(): array
    {
        $volumes = [];

        foreach (self::DIRECTORIES as $purpose => $directoryCode) {
            $volumes[] = $this->checkVolume($purpose, $directoryCode);
        }

        return ['volumes' => $this->section->table('volumes', 'Volumes', $this->getDescription(), $volumes)];
    }

    /**
     * Alertable metric for the disk-space reporter: media volume free space dropping too low.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_MEDIA_FREE_PERCENT,
                'Disk Space: Media Free',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                10,
                15,
                null,
                description: 'Only {value} of media disk space is free, below {threshold}.',
                impact: 'Uploads will fail once the disk is full.',
                criticalThreshold: 5,
                unit: Unit::PERCENT
            ),
        ];
    }

    /**
     * Reads free/total space for one volume and builds its row, treating any failure as unmeasurable.
     *
     * @param string $purpose
     * @param string $directoryCode
     */
    private function checkVolume(string $purpose, string $directoryCode): ArrayField
    {
        try {
            $path = $this->filesystem->getDirectoryRead($directoryCode)->getAbsolutePath();

            if (!$this->filesystemDriver->isDirectory($path)) {
                // disk_free_space()/disk_total_space() emit a PHP warning (not just a false
                // return) for a nonexistent path - e.g. var/cache never gets provisioned on
                // disk at all when a site uses an external cache backend like Redis.
                return $this->volumeField($purpose, false, 0, 0, 0.0);
            }

            $free = disk_free_space($path);
            $total = disk_total_space($path);

            if ($free === false || $total === false || $total <= 0) {
                return $this->volumeField($purpose, false, 0, 0, 0.0);
            }

            return $this->volumeField($purpose, true, (int)$free, (int)$total, round(($free / $total) * 100, 1));
        } catch (Throwable) {
            return $this->volumeField($purpose, false, 0, 0, 0.0);
        }
    }

    /**
     * Builds one volume's row, wiring the media volume's free-percent field to the trackable metric.
     *
     * @param string $purpose
     * @param bool $measurable
     * @param int $freeBytes
     * @param int $totalBytes
     * @param float $freePercent
     */
    private function volumeField(
        string $purpose,
        bool $measurable,
        int $freeBytes,
        int $totalBytes,
        float $freePercent
    ): ArrayField {
        // Only meaningful when we actually got a real reading - an unmeasurable volume's
        // freePercent is just a 0.0 placeholder, not a genuine "out of space" critical value
        // (that's what the separate "Measurable" field already exists to flag).
        $severity = $measurable
            ? ($freePercent < self::FREE_PERCENT_CRITICAL_THRESHOLD ? Field::SEVERITY_CRITICAL : Field::SEVERITY_OK)
            : null;

        $freePercentField = $purpose === 'media'
            ? $this->field->trackableNumber(
                'Free',
                $freePercent,
                self::METRIC_MEDIA_FREE_PERCENT,
                MetricDefinition::AGGREGATION_LATEST,
                $severity,
                unit: Unit::PERCENT
            )
            : $this->field->number('Free', $freePercent, $severity, unit: Unit::PERCENT);

        return $this->field->array($purpose, [
            'name' => $this->field->varchar('Name', $purpose),
            'purpose' => $this->field->varchar('Purpose', self::PURPOSES[$purpose] ?? $purpose),
            'measurable' => $this->field->bool('Measurable', $measurable),
            'free_bytes' => $this->field->number('Free Space', $freeBytes, unit: Unit::BYTES),
            'total_bytes' => $this->field->number('Total Space', $totalBytes, unit: Unit::BYTES),
            'free_percent' => $freePercentField,
        ]);
    }

    /**
     * Run 2 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 2;
    }
}
