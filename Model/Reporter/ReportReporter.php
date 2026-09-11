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
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

final class ReportReporter implements ReporterInterface, DeclaresSectionInterface
{
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function getName(): string
    {
        return 'reports';
    }

    public function getLabel(): string
    {
        return 'Reports';
    }

    public function getDescription(): string
    {
        return 'Recent var/report summaries.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $varDir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        try {
            if (! $varDir->isExist('report')) {
                return ['reports' => Section::table('reports', 'Recent Reports', $this->getDescription(), [])];
            }

            $files = $varDir->read('report');
        } catch (\Throwable) {
            return ['reports' => Section::table('reports', 'Recent Reports', $this->getDescription(), [])];
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
            $recent[] = Field::array('', [
                'name' => Field::varchar('Name', (string) $file),
                'message' => Field::varchar('Message', $message),
            ]);

            $count++;
        }

        return ['reports' => Section::table('reports', 'Recent Reports', $this->getDescription(), $recent)];
    }

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
