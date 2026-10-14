<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\App\MaintenanceMode;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\HeartbeatSender;
use StackNuts\StackGauge\Model\PayloadEnvelope;
use StackNuts\StackGauge\Model\StorefrontProbe;
use StackNuts\StackGauge\Model\Transport;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class HeartbeatSenderTest extends TestCase
{
    /**
     * @param Config $config
     */
    private function envelope(Config $config): PayloadEnvelope
    {
        $lockReader = $this->createMock(ComposerLockReader::class);
        // The heartbeat runs every few minutes - it must not parse composer.lock each time.
        $lockReader->expects($this->never())->method('getDecoded');

        return new PayloadEnvelope($config, $lockReader);
    }

    public function testIncludesStorefrontProbeResultWhenEnabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isSelfProbeEnabled')->willReturn(true);

        $maintenanceMode = $this->createMock(MaintenanceMode::class);
        $maintenanceMode->method('isOn')->willReturn(false);

        $probeResult = [
            'attempted' => true,
            'reachable' => true,
            'http_status' => 200,
            'response_time_ms' => 350,
            'looks_blocked' => false,
            'looks_like_error_page' => false,
        ];
        $storefrontProbe = $this->createMock(StorefrontProbe::class);
        $storefrontProbe->method('probe')->willReturn($probeResult);

        $sentPayload = null;
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturnCallback(
            function (array $payload) use (&$sentPayload): bool {
                $sentPayload = $payload;
                return true;
            }
        );

        $sender = new HeartbeatSender($config, $maintenanceMode, $storefrontProbe, $transport, $this->envelope($config));
        $sender->sendNow();

        $this->assertSame($probeResult, $sentPayload['storefront_probe']);
    }

    public function testOmitsStorefrontProbeWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isSelfProbeEnabled')->willReturn(false);

        $maintenanceMode = $this->createMock(MaintenanceMode::class);
        $maintenanceMode->method('isOn')->willReturn(false);

        $storefrontProbe = $this->createMock(StorefrontProbe::class);
        $storefrontProbe->expects($this->never())->method('probe');

        $sentPayload = null;
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturnCallback(
            function (array $payload) use (&$sentPayload): bool {
                $sentPayload = $payload;
                return true;
            }
        );

        $sender = new HeartbeatSender($config, $maintenanceMode, $storefrontProbe, $transport, $this->envelope($config));
        $sender->sendNow();

        $this->assertArrayNotHasKey('storefront_probe', $sentPayload);
    }

    public function testSendsTheSharedEnvelopeWithoutAModuleVersion(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getSiteId')->willReturn('site-123');

        $sentPayload = null;
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturnCallback(
            function (array $payload) use (&$sentPayload): bool {
                $sentPayload = $payload;
                return true;
            }
        );

        (new HeartbeatSender(
            $config,
            $this->createMock(MaintenanceMode::class),
            $this->createMock(StorefrontProbe::class),
            $transport,
            $this->envelope($config)
        ))->sendNow();

        $this->assertSame('heartbeat', $sentPayload['type']);
        $this->assertSame(['identifier' => 'site-123'], $sentPayload['site']);
        $this->assertArrayHasKey('generated_at', $sentPayload);
        $this->assertArrayNotHasKey('module_version', $sentPayload);
    }
}
