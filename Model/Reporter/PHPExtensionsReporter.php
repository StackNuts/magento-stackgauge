<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;

class PHPExtensionsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface, DeclaresSubjectInterface
{
    use DailyCadenceTrait;
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    private const EXTENSIONS = ['intl', 'gd', 'opcache', 'json', 'curl', 'mbstring'];

    /**
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the PHP-extensions reporter.
     */
    public function getName(): string
    {
        return 'php_extensions';
    }

    /**
     * Human-readable label for the PHP-extensions reporter block.
     */
    public function getLabel(): string
    {
        return 'PHP Extensions';
    }

    /**
     * One-line summary of what the PHP-extensions reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Presence and versions of important PHP extensions.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports enabled/version for each extension in EXTENSIONS, plus memory_limit and OPcache enabled state.
     */
    public function getStatus(): array
    {
        $list = [];
        foreach (self::EXTENSIONS as $ext) {
            $enabled = extension_loaded($ext);
            $version = $enabled ? (string)phpversion($ext) : '';
            $list[] = $this->field->array('', [
                'name' => $this->field->varchar('Name', $ext),
                'enabled' => $this->field->bool('Enabled', $enabled, criticalWhen: false),
                'version' => $this->field->varchar('Version', $version),
            ]);
        }

        $settings = [
            'memory_limit' => $this->field->varchar('Memory Limit', (string)ini_get('memory_limit')),
            'opcache_enabled' => $this->field->bool(
                'OPcache Enabled',
                (bool)ini_get('opcache.enable'),
                criticalWhen: false
            ),
        ];

        return [
            'php_extensions' => $this->section->table('php_extensions', 'Extension Status', '', $list),
            'php_settings' => $this->section->facts('php_settings', 'PHP Settings', '', $settings),
        ];
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'php';
    }
}
