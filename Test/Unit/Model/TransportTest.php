<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\PayloadSigner;
use StackNuts\StackGauge\Model\Transport;

class TransportTest extends TestCase
{
    /**
     * @param string $endpoint
     * @param CurlFactory $factory
     */
    private function transport(string $endpoint, CurlFactory $factory): Transport
    {
        $config = $this->createMock(Config::class);
        $config->method('getEndpointUrl')->willReturn($endpoint);
        $config->method('getApiKey')->willReturn('api-key');
        $config->method('getHmacSecret')->willReturn('secret');

        return new Transport($config, new PayloadSigner(), $factory, new Json(), $this->createMock(LoggerInterface::class));
    }

    public function testAPlainHttpEndpointIsRefusedWithoutSendingAnything(): void
    {
        $factory = $this->createMock(CurlFactory::class);
        $factory->expects($this->never())->method('create');

        $this->assertFalse($this->transport('http://dashboard.example.test/api/ingest', $factory)->send(['type' => 'heartbeat'], 'heartbeat'));
    }

    /**
     * The credentials set on one send's client must not be on any other caller's client.
     */
    public function testEverySendUsesItsOwnClient(): void
    {
        $clients = [];
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$clients): Curl {
            $curl = $this->createMock(Curl::class);
            $curl->method('getStatus')->willReturn(200);
            $curl->expects($this->once())->method('setHeaders')->with($this->callback(
                fn(array $headers): bool => $headers['Authorization'] === 'Bearer api-key'
                    && isset($headers['X-StackGauge-Signature'])
            ));
            $clients[] = $curl;

            return $curl;
        });

        $transport = $this->transport('https://dashboard.example.test/api/ingest', $factory);

        $this->assertTrue($transport->send(['type' => 'heartbeat'], 'heartbeat'));
        $this->assertTrue($transport->send(['type' => 'heartbeat'], 'heartbeat'));
        $this->assertCount(2, $clients);
        $this->assertNotSame($clients[0], $clients[1]);
    }
}
