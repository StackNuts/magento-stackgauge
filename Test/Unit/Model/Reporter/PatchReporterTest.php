<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Shell;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\PatchReporter;
use StackNuts\StackGauge\Model\Util\Clock;
use StackNuts\StackGauge\Model\Util\CommandTimeout;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;
use StackNuts\StackGauge\Model\Util\JsonFileCache;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;

class PatchReporterTest extends TestCase
{
    /**
     * @var string
     */
    private string $binary;

    /**
     * @var string
     */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/patch-reporter-test-' . uniqid();
        mkdir($this->root . '/vendor/bin', 0777, true);
        mkdir($this->root . '/var', 0777, true);
        $this->binary = $this->root . '/vendor/bin/patch-status';
        file_put_contents($this->binary, "#!/bin/sh\n");
        chmod($this->binary, 0755);
    }

    protected function tearDown(): void
    {
        $cache = $this->root . '/var/stackgauge/patch_status_cache.json';
        if (is_file($cache)) {
            unlink($cache);
        }
        if (is_dir($this->root . '/var/stackgauge')) {
            rmdir($this->root . '/var/stackgauge');
        }
        if (is_dir($this->root . '/var')) {
            rmdir($this->root . '/var');
        }
        if (is_file($this->binary)) {
            unlink($this->binary);
        }
    }

    /**
     * @param Shell|null $shell defaults to a mock whose execute() always returns $shellOutput
     */
    private function reporter(string $shellOutput, ?Shell $shell = null, ?int $lockMtime = null): PatchReporter
    {
        $rootRead = $this->createMock(ReadInterface::class);
        $rootRead->method('getAbsolutePath')->willReturn($this->root . '/');

        $varRead = $this->createMock(ReadInterface::class);
        $varRead->method('getAbsolutePath')->willReturn($this->root . '/var/');

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturnCallback(
            fn (string $directoryType) => $directoryType === DirectoryList::VAR_DIR ? $varRead : $rootRead
        );

        if ($shell === null) {
            $shell = $this->createMock(Shell::class);
            $shell->method('execute')->willReturn($shellOutput);
        }

        $logger = $this->createMock(LoggerInterface::class);
        $lock = $this->createMock(ComposerLockReader::class);
        $lock->method('getMtime')->willReturn($lockMtime);
        $timeout = $this->createMock(CommandTimeout::class);
        $timeout->method('prefix')->willReturn('timeout 120 ');

        return new PatchReporter(
            $filesystem,
            new File(),
            $shell,
            new Json(),
            $logger,
            new Field(),
            new Section(),
            new JsonFileCache($filesystem, new File(), new Json(), new Clock(), $logger),
            $lock,
            $timeout,
            new LogLineRedactor()
        );
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
        $this->assertFileDoesNotExist($this->root . '/var/stackgauge/patch_status_cache.json');
    }

    public function testUnrecognizedOutputIsNeverCached(): void
    {
        $this->reporter("Some output patch-status doesn't recognize as JSON.\n")->getStatus();

        $this->assertFileDoesNotExist($this->root . '/var/stackgauge/patch_status_cache.json');
    }

    public function testSecondCallWithinTtlReusesCacheWithoutReRunningShell(): void
    {
        $output = (new Json())->serialize(['applied_patches' => ['ACSD-47259']]);

        $shell = $this->createMock(Shell::class);
        $shell->expects($this->once())->method('execute')->willReturn($output);

        $reporter = $this->reporter($output, $shell);

        $first = $reporter->getStatus();
        $second = $reporter->getStatus();

        $this->assertSame('ACSD-47259', $first['applied_patches']->getRows()[0]->getValue()['patch_id']->getValue());
        $this->assertSame('ACSD-47259', $second['applied_patches']->getRows()[0]->getValue()['patch_id']->getValue());
    }

    public function testStaleCacheTriggersAFreshShellExecution(): void
    {
        $cachePath = $this->root . '/var/stackgauge/patch_status_cache.json';
        mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, (new Json())->serialize([
            'recorded_at' => time() - 90000,
            'data' => ['applied_patches' => ['STALE-ID']],
        ]));

        $output = (new Json())->serialize(['applied_patches' => ['FRESH-ID']]);
        $shell = $this->createMock(Shell::class);
        $shell->expects($this->once())->method('execute')->willReturn($output);

        $status = $this->reporter($output, $shell)->getStatus();

        $this->assertSame('FRESH-ID', $status['applied_patches']->getRows()[0]->getValue()['patch_id']->getValue());
    }

    public function testCorruptCacheIsTreatedAsAMiss(): void
    {
        $cachePath = $this->root . '/var/stackgauge/patch_status_cache.json';
        mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, 'not valid json{{{');

        $output = (new Json())->serialize(['applied_patches' => ['RECOVERED-ID']]);
        $shell = $this->createMock(Shell::class);
        $shell->expects($this->once())->method('execute')->willReturn($output);

        $status = $this->reporter($output, $shell)->getStatus();

        $this->assertSame(
            'RECOVERED-ID',
            $status['applied_patches']->getRows()[0]->getValue()['patch_id']->getValue()
        );
    }

    public function testRendersCommandWithRootTimeoutAndNoStdin(): void
    {
        $rendered = null;
        $shell = $this->createMock(Shell::class);
        $shell->method('execute')->willReturnCallback(function (string $cmd, array $args) use (&$rendered) {
            $rendered = (new \Magento\Framework\Shell\CommandRenderer())->render($cmd, $args);

            return '{"applied_patches":[]}';
        });

        $this->reporter('', $shell)->getStatus();

        $this->assertSame(
            sprintf(
                "{ cd '%1\$s' && timeout 120 '%1\$s/vendor/bin/patch-status' --root='%1\$s' --format=json"
                . ' < /dev/null 2>/dev/null; } 2>&1',
                $this->root
            ),
            $rendered
        );
    }

    public function testKeepsJsonPrintedBeforeANonZeroExit(): void
    {
        $shell = $this->createMock(Shell::class);
        $shell->method('execute')->willThrowException(new \Magento\Framework\Exception\LocalizedException(
            new \Magento\Framework\Phrase('exit 1'),
            new \Exception('{"applied_patches":["ACSD-1"]}', 1)
        ));

        $status = $this->reporter('', $shell)->getStatus();

        $this->assertTrue($status['general']->getFields()['detectable']->getValue());
        $this->assertSame('ACSD-1', $status['applied_patches']->getRows()[0]->getValue()['patch_id']->getValue());
    }

    public function testUnrecognizedOutputIsRedactedAndCapped(): void
    {
        $output = 'Authorization: Bearer abcdef123456 ' . str_repeat('x', 3000);

        $status = $this->reporter($output)->getStatus();
        $text = $status['general']->getFields()['unrecognized_output']->getValue();

        $this->assertStringNotContainsString('abcdef123456', $text);
        $this->assertLessThanOrEqual(1000, strlen($text));
    }

    public function testComposerLockChangeInvalidatesCache(): void
    {
        $shell = $this->createMock(Shell::class);
        $shell->expects($this->exactly(2))->method('execute')->willReturn('{"applied_patches":["A"]}');

        $this->reporter('', $shell, 100)->getStatus();
        $this->reporter('', $shell, 100)->getStatus();
        $this->reporter('', $shell, 200)->getStatus();
    }
}
