<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\PayloadSigner;

class PayloadSignerTest extends TestCase
{
    public function testSignMatchesRawHmacSha256(): void
    {
        $signer = new PayloadSigner();
        $body = '{"schema_version":"1.0"}';
        $secret = 'top-secret';

        $this->assertSame(
            hash_hmac('sha256', $body, $secret),
            $signer->sign($body, $secret)
        );
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $signer = new PayloadSigner();
        $body = '{"a":1}';

        $this->assertNotSame(
            $signer->sign($body, 'secret-one'),
            $signer->sign($body, 'secret-two')
        );
    }

    public function testDifferentBodiesProduceDifferentSignatures(): void
    {
        $signer = new PayloadSigner();
        $secret = 'same-secret';

        $this->assertNotSame(
            $signer->sign('{"a":1}', $secret),
            $signer->sign('{"a":2}', $secret)
        );
    }
}
