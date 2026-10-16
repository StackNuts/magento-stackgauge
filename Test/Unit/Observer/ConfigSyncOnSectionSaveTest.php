<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Observer\ConfigSyncOnSectionSave;

class ConfigSyncOnSectionSaveTest extends TestCase
{
    public function testDoesNothingWhileDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);
        $sender = $this->createMock(ReportSender::class);
        $sender->expects($this->never())->method('sendConfigSync');
        $sender->expects($this->never())->method('sendConfigSyncNow');

        (new ConfigSyncOnSectionSave($sender, $config, $this->createMock(LoggerInterface::class)))
            ->execute(new Observer());
    }

    public function testSyncsWhenEnabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $sender = $this->createMock(ReportSender::class);
        $sender->expects($this->once())->method('sendConfigSync')->willReturn(true);

        (new ConfigSyncOnSectionSave($sender, $config, $this->createMock(LoggerInterface::class)))
            ->execute(new Observer());
    }

    public function testAFailureIsLoggedNeverThrownSoTheSaveIsNotBlocked(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $sender = $this->createMock(ReportSender::class);
        $sender->method('sendConfigSync')->willThrowException(new \RuntimeException('network down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new ConfigSyncOnSectionSave($sender, $config, $logger))->execute(new Observer());
    }
}
