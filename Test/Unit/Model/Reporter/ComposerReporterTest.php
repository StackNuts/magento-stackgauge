<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\ComposerReporter;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ComposerReporterTest extends TestCase
{
    public function testReportsEmptyLockHashAndNoKeyPackagesWhenLockFileIsMissing(): void
    {
        $reader = $this->createStub(ComposerLockReader::class);
        $reader->method('getRawContents')->willReturn(null);

        $status = (new ComposerReporter($reader))->getStatus();

        $this->assertSame('', $status['general']->getFields()['lock_hash']->getValue());
        $this->assertSame([], $status['key_packages']->getFields());
    }

    public function testReportsLockHashAndOnlyWatchedKeyPackages(): void
    {
        $contents = json_encode([
            'packages' => [
                ['name' => 'magento/framework', 'version' => '103.0.5'],
                ['name' => 'some/unrelated-package', 'version' => '1.0.0'],
            ],
        ]);

        $reader = $this->createStub(ComposerLockReader::class);
        $reader->method('getRawContents')->willReturn($contents);
        $reader->method('getDecoded')->willReturn(json_decode($contents, true));

        $status = (new ComposerReporter($reader))->getStatus();

        $this->assertSame('sha256:' . hash('sha256', $contents), $status['general']->getFields()['lock_hash']->getValue());

        $keyPackages = $status['key_packages']->getFields();
        $this->assertArrayHasKey('magento/framework', $keyPackages);
        $this->assertSame('103.0.5', $keyPackages['magento/framework']->getValue());
        $this->assertArrayNotHasKey('some/unrelated-package', $keyPackages);
    }
}
