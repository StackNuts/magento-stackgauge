<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\LogOffsetStore;
use StackNuts\StackGauge\Model\PayloadBuilder;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Model\Transport;

class ReportSenderTest extends TestCase
{
    private Transport&MockObject $transport;
    private LogOffsetStore&MockObject $offsets;
    private LoggerInterface&MockObject $logger;
    private Config&MockObject $config;
    private ReportSender $sender;

    protected function setUp(): void
    {
        $this->transport = $this->createMock(Transport::class);
        $this->offsets = $this->createMock(LogOffsetStore::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);
        $builder = $this->createMock(PayloadBuilder::class);
        $builder->method('build')->willReturn(['bad' => "caf\xE9", 'ok' => 1]);

        $this->sender = new ReportSender($this->config, $builder, $this->transport, $this->logger, $this->offsets);
    }

    public function testOffsetsAreCommittedOnlyAfterASuccessfulSend(): void
    {
        $this->transport->method('send')->willReturn(true);
        $this->offsets->expects($this->once())->method('commit');

        $this->assertTrue($this->sender->sendNow());
    }

    public function testFailedSendDiscardsOffsetsInsteadOfCommitting(): void
    {
        $this->transport->method('send')->willReturn(false);
        $this->offsets->expects($this->never())->method('commit');
        $this->offsets->expects($this->atLeastOnce())->method('discard');

        $this->assertFalse($this->sender->sendNow());
    }

    public function testBuildingAPayloadForPreviewNeverCommits(): void
    {
        $this->offsets->expects($this->never())->method('commit');
        $this->transport->expects($this->never())->method('send');

        $this->sender->buildPayload();
    }

    public function testPayloadIsNotEncodedWhenInfoLoggingIsOff(): void
    {
        $this->config->method('getLogLevel')->willReturn(MonologLogger::WARNING);
        $this->transport->method('send')->willReturn(true);
        $this->logger->expects($this->never())->method('info');

        $this->sender->sendNow();
    }

    public function testInfoLogCarriesThePayloadEvenWithInvalidUtf8(): void
    {
        $this->config->method('getLogLevel')->willReturn(MonologLogger::INFO);
        $this->transport->method('send')->willReturn(true);
        $this->logger->expects($this->once())->method('info')->with(
            $this->logicalAnd(
                $this->stringContains('"ok":1'),
                $this->stringContains('caf\ufffd')
            )
        );

        $this->sender->sendNow();
    }
}
