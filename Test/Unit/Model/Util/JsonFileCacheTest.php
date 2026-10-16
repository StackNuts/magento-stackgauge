<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Util;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use StackNuts\StackGauge\Test\Unit\RemovesTempDirectories;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\Util\Clock;
use StackNuts\StackGauge\Model\Util\JsonFileCache;

class JsonFileCacheTest extends TestCase
{
    use RemovesTempDirectories;

    private string $var;

    protected function setUp(): void
    {
        $this->var = sys_get_temp_dir() . '/json-file-cache-test-' . uniqid();
        mkdir($this->var, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirectory($this->var);
    }

    private function cache(?Clock $clock = null): JsonFileCache
    {
        $read = $this->createStub(ReadInterface::class);
        $read->method('getAbsolutePath')->willReturn($this->var . '/');
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturnCallback(
            fn (string $type) => $type === DirectoryList::VAR_DIR ? $read : $this->fail('wrong dir')
        );

        return new JsonFileCache(
            $filesystem,
            new File(),
            new Json(),
            $clock ?? new Clock(),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testRoundTripsWithinTtl(): void
    {
        $cache = $this->cache();
        $cache->write('sg/x.json', ['a' => 1], 100);

        $this->assertSame(['a' => 1], $cache->read('sg/x.json', 60, 100));
    }

    public function testLockMtimeChangeIsAMiss(): void
    {
        $cache = $this->cache();
        $cache->write('sg/x.json', ['a' => 1], 100);

        $this->assertNull($cache->read('sg/x.json', 60, 101));
    }

    public function testExpiredEntryAndCorruptFileAreMisses(): void
    {
        $this->cache()->write('sg/x.json', ['a' => 1], null);
        $later = $this->createStub(Clock::class);
        $later->method('now')->willReturn(new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC')));

        $this->assertNull($this->cache($later)->read('sg/x.json', 3600, null));

        file_put_contents($this->var . '/sg/x.json', 'nope{');
        $this->assertNull($this->cache()->read('sg/x.json', 3600, null));
    }

    public function testMissingFileIsAMiss(): void
    {
        $this->assertNull($this->cache()->read('sg/none.json', 60, null));
    }
}
