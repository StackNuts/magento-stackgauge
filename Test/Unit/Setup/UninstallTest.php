<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Setup\Uninstall;

class UninstallTest extends TestCase
{
    public function testDropsOffsetTableAndDeletesTheModulesConfigRows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->expects($this->once())->method('dropTable')->with('pfx_stacknuts_stackgauge_log_offset');
        $connection->expects($this->once())->method('delete')
            ->with('pfx_core_config_data', ['path LIKE ?' => 'stacknuts_stackgauge/%']);

        $setup = $this->createMock(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(static fn (string $name): string => 'pfx_' . $name);

        (new Uninstall())->uninstall($setup, $this->createMock(ModuleContextInterface::class));
    }
}
