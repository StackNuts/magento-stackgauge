<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\TlsCertificateInspector;
use StackNuts\StackGauge\Model\Util\Clock;

class TlsCertificateInspectorTest extends TestCase
{
    private function clockAt(string $now): Clock
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable($now, new DateTimeZone('UTC')));

        return $clock;
    }

    /**
     * @param array<string,mixed>|null $verifiedResult What connectAndParse() returns when asked to verify.
     * @param array<string,mixed>|null $unverifiedResult What it returns for the unverified retry.
     */
    private function inspector(
        Clock $clock,
        ?array $verifiedResult,
        ?array $unverifiedResult = null
    ): TlsCertificateInspector {
        return new class ($clock, $verifiedResult, $unverifiedResult) extends TlsCertificateInspector {
            public function __construct(
                Clock $clock,
                private readonly ?array $verifiedResult,
                private readonly ?array $unverifiedResult
            ) {
                parent::__construct($clock);
            }

            protected function connectAndParse(string $host, bool $verifyPeer): ?array
            {
                return $verifyPeer ? $this->verifiedResult : $this->unverifiedResult;
            }
        };
    }

    /**
     * @param string $validTo
     * @param string $issuerCn
     * @param string $subjectCn
     * @return array<string,mixed>
     */
    private function parsedCert(string $validTo, string $issuerCn = "Let's Encrypt", string $subjectCn = 'example.test'): array
    {
        return [
            'subject' => ['CN' => $subjectCn],
            'issuer' => ['CN' => $issuerCn],
            'validTo_time_t' => (new DateTimeImmutable($validTo, new DateTimeZone('UTC')))->getTimestamp(),
        ];
    }

    public function testConnectedAndTrustedCertificateReportsDaysRemaining(): void
    {
        $result = $this->inspector(
            $this->clockAt('2026-10-16 00:00:00'),
            $this->parsedCert('2026-11-05 00:00:00')
        )->inspect('example.test');

        $this->assertTrue($result['attempted']);
        $this->assertTrue($result['connected']);
        $this->assertTrue($result['trusted']);
        $this->assertSame(20, $result['days_remaining']);
        $this->assertSame('example.test', $result['subject']);
        $this->assertSame("Let's Encrypt", $result['issuer']);
        $this->assertNull($result['error']);
    }

    public function testExpiredCertificateReportsNegativeDaysRemaining(): void
    {
        $result = $this->inspector(
            $this->clockAt('2026-10-16 00:00:00'),
            $this->parsedCert('2026-10-01 00:00:00')
        )->inspect('example.test');

        $this->assertTrue($result['connected']);
        $this->assertLessThan(0, $result['days_remaining']);
        $this->assertSame(-15, $result['days_remaining']);
    }

    public function testVerificationFailureRetriesUnverifiedAndReportsUntrusted(): void
    {
        $result = $this->inspector(
            $this->clockAt('2026-10-16 00:00:00'),
            null,
            $this->parsedCert('2026-11-05 00:00:00')
        )->inspect('self-signed.test');

        $this->assertTrue($result['connected']);
        $this->assertFalse($result['trusted']);
        $this->assertNotNull($result['error']);
        $this->assertSame(20, $result['days_remaining']);
    }

    public function testConnectionFailureInBothAttemptsReportsNotConnected(): void
    {
        $result = $this->inspector($this->clockAt('2026-10-16 00:00:00'), null, null)->inspect('unreachable.test');

        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['connected']);
        $this->assertFalse($result['trusted']);
        $this->assertNotNull($result['error']);
    }

    public function testIssuerFallsBackToOrganizationWhenNoCommonName(): void
    {
        $result = $this->inspector(
            $this->clockAt('2026-10-16 00:00:00'),
            [
                'subject' => ['CN' => 'example.test'],
                'issuer' => ['O' => 'Some CA Org'],
                'validTo_time_t' => (new DateTimeImmutable('2026-11-05 00:00:00', new DateTimeZone('UTC')))
                    ->getTimestamp(),
            ]
        )->inspect('example.test');

        $this->assertSame('Some CA Org', $result['issuer']);
    }
}
