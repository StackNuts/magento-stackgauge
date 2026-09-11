<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

final class PHPExtensionsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    private const EXTENSIONS = ['intl', 'gd', 'opcache', 'json', 'curl', 'mbstring'];

    public function getName(): string
    {
        return 'php_extensions';
    }

    public function getLabel(): string
    {
        return 'PHP Extensions';
    }

    public function getDescription(): string
    {
        return 'Presence and versions of important PHP extensions.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $list = [];
        foreach (self::EXTENSIONS as $ext) {
            $enabled = extension_loaded($ext);
            $version = $enabled ? (string)phpversion($ext) : '';
            $list[] = Field::array('', [
                'name' => Field::varchar('Name', $ext),
                'enabled' => Field::bool('Enabled', $enabled, criticalWhen: false),
                'version' => Field::varchar('Version', $version),
            ]);
        }

        $settings = [
            'memory_limit' => Field::varchar('Memory Limit', (string)ini_get('memory_limit')),
            'opcache_enabled' => Field::bool('OPcache Enabled', (bool)ini_get('opcache.enable'), criticalWhen: false),
        ];

        return [
            'php_extensions' => Section::table('php_extensions', 'Extension Status', '', $list),
            'php_settings' => Section::facts('php_settings', 'PHP Settings', '', $settings),
        ];
    }
}
