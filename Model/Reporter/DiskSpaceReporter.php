<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;
use Throwable;

/**
 * Free/total disk space for var/log, var/cache, and media. Often the same underlying
 * filesystem on a typical install, but not guaranteed - media in particular is sometimes on
 * a separate volume - so each is checked independently.
 */
class DiskSpaceReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface
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

    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'disk';
    }

    public function getLabel(): string
    {
        return 'Disk Space';
    }

    public function getDescription(): string
    {
        return 'Free/total bytes for var/log, var/cache, and media, checked independently.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $volumes = [];

        foreach (self::DIRECTORIES as $purpose => $directoryCode) {
            $volumes[] = $this->checkVolume($purpose, $directoryCode);
        }

        return ['volumes' => Section::table('volumes', 'Volumes', $this->getDescription(), $volumes)];
    }

    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_MEDIA_FREE_PERCENT,
                'Disk Space: Media Free %',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                10,
                15
            ),
        ];
    }

    private function checkVolume(string $purpose, string $directoryCode): ArrayField
    {
        try {
            $path = $this->filesystem->getDirectoryRead($directoryCode)->getAbsolutePath();
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
            ? Field::trackableNumber(
                'Free Percent',
                $freePercent,
                self::METRIC_MEDIA_FREE_PERCENT,
                MetricDefinition::AGGREGATION_LATEST,
                $severity
            )
            : Field::number('Free Percent', $freePercent, $severity);

        return Field::array($purpose, [
            'name' => Field::varchar('Name', $purpose),
            'purpose' => Field::varchar('Purpose', self::PURPOSES[$purpose] ?? $purpose),
            'measurable' => Field::bool('Measurable', $measurable),
            'free_bytes' => Field::number('Free Bytes', $freeBytes),
            'total_bytes' => Field::number('Total Bytes', $totalBytes),
            'free_percent' => $freePercentField,
        ]);
    }
}
