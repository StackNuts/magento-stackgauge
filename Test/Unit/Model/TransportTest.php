<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
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

        return new Transport($config, new PayloadSigner(), $factory, $this->createMock(LoggerInterface::class));
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

    /**
     * @param array<string,mixed> $payload
     * @param int $status
     * @param string|null $secret
     * @param array<string,mixed> $captured receives headers, body and the logger warnings
     */
    private function sendCapturing(array $payload, int $status, ?string $secret, array &$captured, ?\Throwable $postThrows = null): bool
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn('nope');
        $curl->method('setHeaders')->willReturnCallback(function (array $h) use (&$captured): void {
            $captured['headers'] = $h;
        });
        $curl->method('post')->willReturnCallback(function (string $url, $body) use (&$captured, $postThrows): void {
            $captured['body'] = $body;
            if ($postThrows !== null) {
                throw $postThrows;
            }
        });
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        $config = $this->createMock(Config::class);
        $config->method('getEndpointUrl')->willReturn('https://dashboard.example.test/api/ingest');
        $config->method('getApiKey')->willReturn('api-key');
        $config->method('getHmacSecret')->willReturn($secret);

        $captured['warnings'] = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $m) use (&$captured): void {
            $captured['warnings'][] = $m;
        });

        return (new Transport($config, new PayloadSigner(), $factory, $logger))->send($payload, 'report');
    }

    public function testSignatureIsOverTheRawUncompressedBody(): void
    {
        $captured = [];
        $payload = ['type' => 'full', 'pad' => str_repeat('a', 3000)];

        $this->assertTrue($this->sendCapturing($payload, 200, 'secret', $captured));
        $this->assertSame('gzip', $captured['headers']['Content-Encoding']);
        $raw = gzdecode($captured['body']);
        $this->assertSame(json_encode($payload), $raw);
        $this->assertSame((new PayloadSigner())->sign($raw, 'secret'), $captured['headers']['X-StackGauge-Signature']);
        $this->assertSame([], $captured['warnings']);
    }

    public function testSmallBodiesAreNotGzipped(): void
    {
        $captured = [];

        $this->sendCapturing(['type' => 'heartbeat'], 200, 'secret', $captured);

        $this->assertArrayNotHasKey('Content-Encoding', $captured['headers']);
        $this->assertSame('{"type":"heartbeat"}', $captured['body']);
    }

    public function testABlankSecretSendsUnsignedAndWarns(): void
    {
        $captured = [];

        $this->assertTrue($this->sendCapturing(['type' => 'heartbeat'], 200, '', $captured));
        $this->assertArrayNotHasKey('X-StackGauge-Signature', $captured['headers']);
        $this->assertStringContainsString('without an HMAC signature', $captured['warnings'][0]);
    }

    public function testUserAgentCarriesTheModuleVersion(): void
    {
        $captured = [];

        $this->sendCapturing(['type' => 'full', 'module_version' => '0.6.0'], 200, 'secret', $captured);
        $this->assertSame('StackGauge/0.6.0', $captured['headers']['User-Agent']);

        $this->sendCapturing(['type' => 'heartbeat'], 200, 'secret', $captured);
        $this->assertSame('StackGauge/unknown', $captured['headers']['User-Agent']);
    }

    public function testANon2xxResponseReturnsFalseAndWarns(): void
    {
        $captured = [];

        $this->assertFalse($this->sendCapturing(['type' => 'heartbeat'], 503, 'secret', $captured));
        $this->assertStringContainsString('HTTP 503', $captured['warnings'][0]);
    }

    public function testACurlExceptionReturnsFalseAndWarns(): void
    {
        $captured = [];

        $this->assertFalse($this->sendCapturing(['type' => 'heartbeat'], 0, 'secret', $captured, new \RuntimeException('timed out')));
        $this->assertStringContainsString('timed out', $captured['warnings'][0]);
    }

    public function testInvalidUtf8IsSubstitutedRatherThanLosingThePayload(): void
    {
        $captured = [];

        $this->assertTrue($this->sendCapturing(['type' => 'heartbeat', 'tail' => "cut mid-char \xC3"], 200, 'secret', $captured));
        $this->assertSame(['type' => 'heartbeat', 'tail' => "cut mid-char \u{FFFD}"], json_decode($captured['body'], true));
    }

    public function testAnUnencodablePayloadReturnsFalseInsteadOfThrowing(): void
    {
        $captured = [];

        $this->assertFalse($this->sendCapturing(['type' => 'full', 'bad' => NAN], 200, 'secret', $captured));
        $this->assertArrayNotHasKey('body', $captured);
        $this->assertStringContainsString('could not be encoded', $captured['warnings'][0]);
    }
}
