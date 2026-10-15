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
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\HealthSectionTrait;

class ReportReporter implements ReporterInterface, DeclaresSectionInterface
{
    use HealthSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param Filesystem $filesystem
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the reports reporter.
     */
    public function getName(): string
    {
        return 'reports';
    }

    /**
     * Human-readable label for the reports reporter block.
     */
    public function getLabel(): string
    {
        return 'Reports';
    }

    /**
     * One-line summary of what the reports reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Recent var/report summaries.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports up to five most-recent var/report file names, each with a distilled exception/error message.
     */
    public function getStatus(): array
    {
        $varDir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        try {
            if (! $varDir->isExist('report')) {
                return ['reports' => $this->section->table('reports', 'Recent Reports', $this->getDescription(), [])];
            }

            $files = $varDir->read('report');
        } catch (\Throwable) {
            return ['reports' => $this->section->table('reports', 'Recent Reports', $this->getDescription(), [])];
        }

        $recent = [];
        $count = 0;
        foreach ($files as $file) {
            if ($count >= 5) {
                break;
            }

            try {
                $content = $varDir->readFile('report/' . $file);
            } catch (\Throwable) {
                continue;
            }

            $message = $this->extractMessage($content);
            $recent[] = $this->field->array('', [
                'name' => $this->field->varchar('Name', (string) $file),
                'message' => $this->field->varchar('Message', $message),
            ]);

            $count++;
        }

        return ['reports' => $this->section->table('reports', 'Recent Reports', $this->getDescription(), $recent)];
    }

    /**
     * Pulls a short exception/error message out of a var/report file's raw content, falling back to a plain excerpt.
     *
     * @param string $content
     */
    private function extractMessage(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (preg_match('/(?:Exception|Error):\s*(.{1,300})/m', $content, $m)) {
            return trim($m[1]);
        }

        return mb_substr($content, 0, 200);
    }
}
