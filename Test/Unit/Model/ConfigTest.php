<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Config;

class ConfigTest extends TestCase
{
    private function config(?string $rawValue): Config
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with('stacknuts_stackgauge/general/additional_log_files')
            ->willReturn($rawValue);

        return new Config($scopeConfig, $this->createMock(EncryptorInterface::class), new Json());
    }

    private function rows(array $rows): string
    {
        return json_encode($rows);
    }

    public function testReadsFilenameAndDisplayNamePerRow(): void
    {
        $files = $this->config($this->rows([
            ['name' => 'System Log', 'file' => 'system.log'],
            ['name' => 'Exception Log', 'file' => 'exception.log'],
        ]))->getMonitoredLogFiles();

        $this->assertSame(
            ['system.log' => 'System Log', 'exception.log' => 'Exception Log'],
            $files
        );
    }

    public function testABlankNameFallsBackToTheFilename(): void
    {
        $files = $this->config($this->rows([
            ['name' => '', 'file' => 'custom.log'],
        ]))->getMonitoredLogFiles();

        $this->assertSame(['custom.log' => 'custom.log'], $files);
    }

    public function testDropsRowsWithABlankFile(): void
    {
        $files = $this->config($this->rows([
            ['name' => 'Nothing Here', 'file' => ''],
            ['name' => 'Real Row', 'file' => 'real.log'],
        ]))->getMonitoredLogFiles();

        $this->assertSame(['real.log' => 'Real Row'], $files);
    }

    public function testDropsRowsWhoseFileContainsAPathSeparatorOrTraversal(): void
    {
        $files = $this->config($this->rows([
            ['name' => 'Sneaky', 'file' => '../etc/passwd'],
            ['name' => 'Also Sneaky', 'file' => 'var/log/system.log'],
            ['name' => 'Fine', 'file' => 'safe.log'],
        ]))->getMonitoredLogFiles();

        $this->assertSame(['safe.log' => 'Fine'], $files);
    }

    public function testEmptyOrMissingConfigReturnsNoFiles(): void
    {
        $this->assertSame([], $this->config(null)->getMonitoredLogFiles());
        $this->assertSame([], $this->config('')->getMonitoredLogFiles());
    }

    public function testMalformedJsonReturnsNoFiles(): void
    {
        $this->assertSame([], $this->config('not json')->getMonitoredLogFiles());
    }
}
