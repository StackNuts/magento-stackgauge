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
use StackNuts\StackGauge\Model\Reporter\PatchInstallerReporter;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Util\Clock;
use StackNuts\StackGauge\Model\Util\CommandTimeout;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;
use StackNuts\StackGauge\Model\Util\JsonFileCache;

class PatchInstallerReporterTest extends TestCase
{
    /**
     * @var string
     */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/patch-installer-reporter-test-' . uniqid();
        mkdir($this->root . '/var', 0777, true);
    }

    protected function tearDown(): void
    {
        $cache = $this->root . '/var/stackgauge/patch_installer_cache.json';
        if (is_file($cache)) {
            unlink($cache);
        }
        if (is_dir($this->root . '/var/stackgauge')) {
            rmdir($this->root . '/var/stackgauge');
        }
        if (is_dir($this->root . '/var')) {
            rmdir($this->root . '/var');
        }
    }

    /**
     * @param array<int, array<string, mixed>>|null $lockPackages null means composer.lock has no
     *     samjuk/magento-patch-installer entry at all
     */
    private function reporter(
        ?array $lockPackages,
        string $shellOutput = '',
        ?Shell $shell = null,
        string $composerBinary = 'composer'
    ): PatchInstallerReporter {
        $composerLockReader = $this->createMock(ComposerLockReader::class);
        $composerLockReader->method('getDecoded')->willReturn(
            $lockPackages === null ? [] : ['packages' => $lockPackages]
        );

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
        $timeout = $this->createMock(CommandTimeout::class);
        $timeout->method('prefix')->willReturn('timeout 120 ');
        $config = $this->createMock(Config::class);
        $config->method('getComposerBinary')->willReturn($composerBinary);

        return new PatchInstallerReporter(
            $composerLockReader,
            $filesystem,
            $shell,
            new Json(),
            $logger,
            new Field(),
            new Section(),
            new JsonFileCache($filesystem, new File(), new Json(), new Clock(), $logger),
            $timeout,
            $config
        );
    }

    private function installedPackages(): array
    {
        return [['name' => 'samjuk/magento-patch-installer', 'version' => 'v0.2.0']];
    }

    public function testUndetectableWhenPackageNotInComposerLock(): void
    {
        $shell = $this->createMock(Shell::class);
        $shell->expects($this->never())->method('execute');

        $status = $this->reporter(null, shell: $shell)->getStatus();

        $this->assertFalse($status['general']->getFields()['detectable']->getValue());
        $this->assertSame([], $status['applied_patches']->getRows());
    }

    public function testAppliedPatchNeedsEveryTargetAlready(): void
    {
        $json = new Json();
        $output = $json->serialize([
            'patches' => [
                [
                    'id' => '2026-09-001',
                    'label' => '2026-09-001 September Isolated [2.4.6-p15]',
                    'line' => '2.4.6-p15',
                    'applicable' => true,
                    'blocked' => false,
                    'targets' => [
                        ['path' => 'vendor/magento/framework/Escaper.php', 'state' => 'already'],
                        ['path' => 'vendor/magento/module-sales/Controller/Order.php', 'state' => 'already'],
                    ],
                ],
                [
                    'id' => '2026-10-001',
                    'label' => '2026-10-001 October Isolated [2.4.6-p15]',
                    'line' => '2.4.6-p15',
                    'applicable' => true,
                    'blocked' => false,
                    'targets' => [
                        ['path' => 'vendor/magento/framework/Escaper.php', 'state' => 'applicable'],
                    ],
                ],
                [
                    'id' => '2026-10-002',
                    'label' => 'Not applicable here',
                    'line' => '2.4.8-p5',
                    'applicable' => false,
                    'blocked' => false,
                    'targets' => [],
                ],
            ],
        ]);

        $status = $this->reporter($this->installedPackages(), $output)->getStatus();

        $this->assertTrue($status['general']->getFields()['detectable']->getValue());

        $appliedRows = $status['applied_patches']->getRows();
        $this->assertCount(1, $appliedRows);
        $this->assertSame('2026-09-001', $appliedRows[0]->getValue()['id']->getValue());
        $this->assertSame('2.4.6-p15', $appliedRows[0]->getValue()['line']->getValue());

        $notAppliedRows = $status['not_applied_patches']->getRows();
        $this->assertCount(1, $notAppliedRows);
        $this->assertSame('2026-10-001', $notAppliedRows[0]->getValue()['id']->getValue());
    }

    public function testAPatchWithNoTargetsIsNotCountedAsApplied(): void
    {
        $json = new Json();
        $output = $json->serialize([
            'patches' => [
                [
                    'id' => '2026-09-001',
                    'line' => '2.4.6-p15',
                    'applicable' => true,
                    'blocked' => false,
                    'targets' => [],
                ],
            ],
        ]);

        $status = $this->reporter($this->installedPackages(), $output)->getStatus();

        $this->assertSame([], $status['applied_patches']->getRows());
        $this->assertCount(1, $status['not_applied_patches']->getRows());
    }

    public function testUndetectableWhenShellExecutionFails(): void
    {
        $shell = $this->createMock(Shell::class);
        $shell->method('execute')->willThrowException(new \RuntimeException('composer not found'));

        $status = $this->reporter($this->installedPackages(), shell: $shell)->getStatus();

        $this->assertFalse($status['general']->getFields()['detectable']->getValue());
    }

    public function testUndetectableWhenOutputIsNotRecognizedJson(): void
    {
        $status = $this->reporter($this->installedPackages(), "not json at all")->getStatus();

        $this->assertFalse($status['general']->getFields()['detectable']->getValue());
    }

    public function testSecondCallWithinTtlReusesCacheWithoutReRunningShell(): void
    {
        $output = (new Json())->serialize(['patches' => [
            ['id' => '2026-09-001', 'line' => '2.4.6-p15', 'applicable' => true, 'blocked' => false, 'targets' => [
                ['path' => 'x', 'state' => 'already'],
            ]],
        ]]);

        $shell = $this->createMock(Shell::class);
        $shell->expects($this->once())->method('execute')->willReturn($output);

        $reporter = $this->reporter($this->installedPackages(), $output, $shell);

        $first = $reporter->getStatus();
        $second = $reporter->getStatus();

        $this->assertCount(1, $first['applied_patches']->getRows());
        $this->assertCount(1, $second['applied_patches']->getRows());
    }

    public function testStaleCacheTriggersAFreshShellExecution(): void
    {
        $cachePath = $this->root . '/var/stackgauge/patch_installer_cache.json';
        mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, (new Json())->serialize([
            'recorded_at' => time() - 90000,
            'data' => ['patches' => [
                ['id' => 'STALE-ID', 'line' => '2.4.6-p15', 'applicable' => true, 'blocked' => false, 'targets' => [
                    ['path' => 'x', 'state' => 'already'],
                ]],
            ]],
        ]));

        $output = (new Json())->serialize(['patches' => [
            ['id' => 'FRESH-ID', 'line' => '2.4.6-p15', 'applicable' => true, 'blocked' => false, 'targets' => [
                ['path' => 'x', 'state' => 'already'],
            ]],
        ]]);
        $shell = $this->createMock(Shell::class);
        $shell->expects($this->once())->method('execute')->willReturn($output);

        $status = $this->reporter($this->installedPackages(), $output, $shell)->getStatus();

        $this->assertSame('FRESH-ID', $status['applied_patches']->getRows()[0]->getValue()['id']->getValue());
    }

    public function testRendersCommandWithConfiguredBinaryTimeoutAndNoStdin(): void
    {
        $rendered = null;
        $shell = $this->createMock(Shell::class);
        $shell->method('execute')->willReturnCallback(function (string $cmd, array $args) use (&$rendered) {
            $rendered = (new \Magento\Framework\Shell\CommandRenderer())->render($cmd, $args);

            return '{"patches":[]}';
        });

        $this->reporter($this->installedPackages(), '', $shell, '/opt/bin/composer')->getStatus();

        $this->assertMatchesRegularExpression(
            '#^\{ cd \'' . preg_quote($this->root, '#') . '\' && (COMPOSER_HOME=\'[^\']+\' )?timeout 120 '
            . '\'/opt/bin/composer\' magento-patches:status --json --no-interaction --no-ansi '
            . '< /dev/null 2>/dev/null; \} 2>&1$#',
            (string)$rendered
        );
    }
}
