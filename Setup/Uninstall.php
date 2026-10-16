<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Cleans up this module's own state on `bin/magento module:uninstall` - nothing else.
 * StackGauge never touches catalog/sales/customer data, so that is the log read offsets in
 * Model\LogOffsetStore and its stacknuts_stackgauge/* rows in core_config_data (which include
 * the encrypted API key and HMAC secret, so they must not outlive the module).
 */
class Uninstall implements UninstallInterface
{
    /**
     * Drops the log-offset table, if it exists, and deletes every stacknuts_stackgauge/* config row.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $connection = $setup->getConnection();
        $table = $setup->getTable('stacknuts_stackgauge_log_offset');

        if ($connection->isTableExists($table)) {
            $connection->dropTable($table);
        }

        $configTable = $setup->getTable('core_config_data');
        $connection->delete($configTable, ['path LIKE ?' => 'stacknuts_stackgauge/%']);

        $setup->endSetup();
    }
}
