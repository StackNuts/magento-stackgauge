<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Util;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ComposerLockReaderTest extends TestCase
{
    private function readerReturning(?string $contents): ComposerLockReader
    {
        $dir = $this->createStub(ReadInterface::class);
        $dir->method('isExist')->willReturn($contents !== null);
        $dir->method('readFile')->willReturn($contents ?? '');

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($dir);

        return new ComposerLockReader($filesystem, new Json());
    }

    public function testGetRawContentsReturnsNullWhenLockFileIsMissing(): void
    {
        $this->assertNull($this->readerReturning(null)->getRawContents());
    }

    public function testGetRawContentsReturnsFileContentsWhenPresent(): void
    {
        $reader = $this->readerReturning('{"packages":[]}');

        $this->assertSame('{"packages":[]}', $reader->getRawContents());
    }

    public function testGetDecodedReturnsNullWhenLockFileIsMissing(): void
    {
        $this->assertNull($this->readerReturning(null)->getDecoded());
    }

    public function testGetDecodedReturnsNullOnMalformedJson(): void
    {
        $this->assertNull($this->readerReturning('not json')->getDecoded());
    }

    public function testGetDecodedReturnsParsedPackages(): void
    {
        $reader = $this->readerReturning(json_encode([
            'packages' => [['name' => 'magento/framework', 'version' => '103.0.5']],
        ]));

        $this->assertSame(
            [['name' => 'magento/framework', 'version' => '103.0.5']],
            $reader->getDecoded()['packages']
        );
    }
}
