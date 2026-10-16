<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Logger;

use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Logger\Logger;
use StackNuts\StackGauge\Model\Config;

class LoggerTest extends TestCase
{
    /**
     * @param int $configuredLevel
     * @param LoggerInterface $writer
     */
    private function logger(int $configuredLevel, LoggerInterface $writer): Logger
    {
        $config = $this->createStub(Config::class);
        $config->method('getLogLevel')->willReturn($configuredLevel);

        return new Logger($writer, $config);
    }

    public function testPsrStringLevelsAreGatedAndForwarded(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->once())->method('log')->with(MonologLogger::WARNING, 'kept', []);

        $logger = $this->logger(MonologLogger::WARNING, $writer);
        $logger->log('info', 'dropped');
        $logger->log('warning', 'kept');
    }

    public function testAnUnknownLevelIsIgnoredWithoutThrowing(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->never())->method('log');

        $this->logger(MonologLogger::DEBUG, $writer)->log('bogus', 'x');
    }

    public function testNamedMethodsRespectTheConfiguredLevel(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->once())->method('log')->with(MonologLogger::ERROR, 'boom', []);

        $logger = $this->logger(MonologLogger::ERROR, $writer);
        $logger->warning('quiet');
        $logger->error('boom');
    }
}
