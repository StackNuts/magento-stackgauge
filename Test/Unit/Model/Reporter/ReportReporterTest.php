<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\ReportReporter;

class ReportReporterTest extends TestCase
{
    public function testEmptyWhenNoReports(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $varDir = $this->createMock(ReadInterface::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::VAR_DIR)->willReturn($varDir);
        $varDir->method('isExist')->willReturn(false);

        $reporter = new ReportReporter($filesystem);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('reports', $status);
        $this->assertSame([], $status['reports']->getRows());
    }
}
