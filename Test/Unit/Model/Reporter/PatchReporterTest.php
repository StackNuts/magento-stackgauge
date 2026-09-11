<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Shell;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\Reporter\PatchReporter;

class PatchReporterTest extends TestCase
{
    private string $binary;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/patch-reporter-test-' . uniqid();
        mkdir($root . '/vendor/bin', 0777, true);
        $this->binary = $root . '/vendor/bin/patch-status';
        file_put_contents($this->binary, "#!/bin/sh\n");
        chmod($this->binary, 0755);
    }

    protected function tearDown(): void
    {
        @unlink($this->binary);
    }

    private function reporter(string $shellOutput): PatchReporter
    {
        $read = $this->createMock(ReadInterface::class);
        $read->method('getAbsolutePath')->willReturn(dirname(dirname(dirname($this->binary))) . '/');

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::ROOT)->willReturn($read);

        $shell = $this->createMock(Shell::class);
        $shell->method('execute')->willReturn($shellOutput);

        return new PatchReporter($filesystem, $shell, new Json(), $this->createMock(LoggerInterface::class));
    }

    public function testParsesRecognizedJsonIntoStructuredSections(): void
    {
        $json = new Json();
        $output = $json->serialize([
            'base_version' => '2.4.7-p2',
            'registry_source' => 'https://example.com/patches.json',
            'installed_components' => ['magento/product-community-edition' => '2.4.7'],
            'applied_patches' => ['ACSD-47259'],
            'missing_patches' => ['VULN-39341'],
            'unknown_patches' => [],
            'vulnerability_status' => ['CVE-2024-1234' => ['status' => 'PROTECTED']],
            'warnings' => ['Could not reach patch registry'],
        ]);

        $status = $this->reporter($output)->getStatus();

        $generalFields = $status['general']->getFields();
        $this->assertTrue($generalFields['detectable']->getValue());
        $this->assertSame('2.4.7-p2', $generalFields['base_version']->getValue());
        $this->assertSame('https://example.com/patches.json', $generalFields['registry_source']->getValue());

        $componentRows = $status['installed_components']->getRows();
        $this->assertCount(1, $componentRows);
        $this->assertSame('magento/product-community-edition', $componentRows[0]->getValue()['component']->getValue());
        $this->assertSame('2.4.7', $componentRows[0]->getValue()['version']->getValue());

        $appliedRows = $status['applied_patches']->getRows();
        $this->assertCount(1, $appliedRows);
        $this->assertSame('ACSD-47259', $appliedRows[0]->getValue()['patch_id']->getValue());

        $missingRows = $status['missing_patches']->getRows();
        $this->assertCount(1, $missingRows);
        $this->assertSame('VULN-39341', $missingRows[0]->getValue()['patch_id']->getValue());

        $this->assertSame([], $status['unknown_patches']->getRows());

        $vulnRows = $status['vulnerability_status']->getRows();
        $this->assertCount(1, $vulnRows);
        $this->assertSame('CVE-2024-1234', $vulnRows[0]->getValue()['cve']->getValue());
        $this->assertSame('PROTECTED', $vulnRows[0]->getValue()['status']->getValue());

        $warningRows = $status['warnings']->getRows();
        $this->assertCount(1, $warningRows);
        $this->assertSame('Could not reach patch registry', $warningRows[0]->getValue()['message']->getValue());
    }

    public function testNonMatchingOutputProducesUnrecognizedOutputField(): void
    {
        $status = $this->reporter("Some output patch-status doesn't recognize as JSON.\n")->getStatus();

        $generalFields = $status['general']->getFields();
        $this->assertTrue($generalFields['detectable']->getValue());
        $this->assertSame(
            "Some output patch-status doesn't recognize as JSON.",
            $generalFields['unrecognized_output']->getValue()
        );
        $this->assertSame([], $status['applied_patches']->getRows());
    }

    public function testUndetectableWhenBinaryMissing(): void
    {
        unlink($this->binary);

        $status = $this->reporter('irrelevant')->getStatus();

        $this->assertFalse($status['general']->getFields()['detectable']->getValue());
        $this->assertSame([], $status['applied_patches']->getRows());
    }
}
