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

    /**
     * Magento's required and commonly missed extensions. json is always built in on PHP 8, so
     * checking it says nothing.
     */
    private const EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'dom', 'gd', 'intl', 'mbstring', 'opcache', 'openssl', 'pdo_mysql',
        'simplexml', 'soap', 'sockets', 'sodium', 'xsl', 'zip',
    ];

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
        return 'PHP Extensions (CLI)';
    }

    /**
     * One-line summary of what the PHP-extensions reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Presence and versions of important PHP extensions, as seen by the CLI/cron PHP. The web (FPM) PHP can be configured differently.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports enabled/version for each extension in EXTENSIONS, plus the CLI memory_limit and OPcache state.
     */
    public function getStatus(): array
    {
        $list = [];
        foreach (self::EXTENSIONS as $ext) {
            $enabled = extension_loaded($ext);
            $version = $enabled ? (string)phpversion($ext) : '';
            $list[] = $this->field->array($ext, [
                'name' => $this->field->varchar('Name', $ext),
                'enabled' => $this->field->bool('Enabled', $enabled, criticalWhen: false),
                'version' => $this->field->varchar('Version', $version),
            ]);
        }

        $settings = [
            // Both are the CLI/cron process's own settings, which routinely differ from FPM's
            // (opcache.enable_cli is usually off), so neither is coloured as a problem.
            'memory_limit' => $this->field->varchar('Memory Limit (CLI)', (string)ini_get('memory_limit')),
            'opcache_enabled' => $this->field->bool('OPcache Enabled (CLI)', (bool)ini_get('opcache.enable')),
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
