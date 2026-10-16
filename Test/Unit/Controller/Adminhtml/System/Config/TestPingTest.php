<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Controller\Adminhtml\System\Config\TestPing;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\HeartbeatSender;
use StackNuts\StackGauge\Model\ReportRun;
use StackNuts\StackGauge\Model\ReportRunner;
use StackNuts\StackGauge\Model\Slot;

class TestPingTest extends TestCase
{
    private Config&MockObject $config;
    private ReportRunner&MockObject $runner;
    private HeartbeatSender&MockObject $heartbeat;
    private LockManagerInterface&MockObject $lock;
    /** @var array<string, mixed> */
    private array $response = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->runner = $this->createMock(ReportRunner::class);
        $this->heartbeat = $this->createMock(HeartbeatSender::class);
        $this->lock = $this->createMock(LockManagerInterface::class);
    }

    private function controller(): TestPing
    {
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function (array $data) use ($json): Json {
            $this->response = $data;
            return $json;
        });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($json);

        return new TestPing(
            $this->createMock(Context::class),
            $factory,
            $this->config,
            $this->runner,
            $this->heartbeat,
            $this->lock
        );
    }

    private function configured(): void
    {
        $this->config->method('getEndpointUrl')->willReturn('https://dash.example.test/api/ingest');
        $this->config->method('getApiKey')->willReturn('key');
    }

    public function testUsesTheModulesOwnAclResource(): void
    {
        $this->assertSame('StackNuts_StackGauge::config', TestPing::ADMIN_RESOURCE);
    }

    public function testRefusesWithoutEndpointAndKeyAndNeverLocksOrSends(): void
    {
        $this->config->method('getEndpointUrl')->willReturn(null);
        $this->lock->expects($this->never())->method('lock');
        $this->heartbeat->expects($this->never())->method('sendNow');

        $this->controller()->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsAConcurrentTestWithoutSending(): void
    {
        $this->configured();
        $this->lock->method('lock')->with(TestPing::LOCK_NAME, 0)->willReturn(false);
        $this->lock->expects($this->never())->method('unlock');
        $this->heartbeat->expects($this->never())->method('sendNow');
        $this->runner->expects($this->never())->method('send');

        $this->controller()->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString('already running', $this->response['message']);
    }

    public function testSendsHeartbeatCurrentSlotAndConfigSyncOnlyThenUnlocks(): void
    {
        $this->configured();
        $current = new ReportRun('hourly', new Slot(1, 3));
        $sync = new ReportRun(null);
        $this->runner->expects($this->exactly(2))->method('resolve')
            ->willReturnMap([
                [ReportRunner::TARGET_CURRENT, [$current]],
                ['config', [$sync]],
            ]);
        $this->runner->expects($this->never())->method('all');
        $sent = [];
        $this->runner->expects($this->exactly(2))->method('send')
            ->willReturnCallback(function (ReportRun $run, bool $force) use (&$sent): bool {
                $sent[] = [$run, $force];
                return true;
            });
        $this->heartbeat->expects($this->once())->method('sendNow');
        $this->lock->method('lock')->willReturn(true);
        $this->lock->expects($this->once())->method('unlock')->with(TestPing::LOCK_NAME);

        $this->controller()->execute();

        $this->assertTrue($this->response['success']);
        $this->assertSame([[$current, true], [$sync, true]], $sent);
    }

    public function testReportsWhichRunsTheDashboardRejectedAndStillUnlocks(): void
    {
        $this->configured();
        $current = new ReportRun('hourly', new Slot(0, 3));
        $this->runner->method('resolve')->willReturn([$current]);
        $this->runner->method('send')->willReturn(false);
        $this->lock->method('lock')->willReturn(true);
        $this->lock->expects($this->once())->method('unlock');

        $this->controller()->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString($current->label(), $this->response['message']);
    }
}
