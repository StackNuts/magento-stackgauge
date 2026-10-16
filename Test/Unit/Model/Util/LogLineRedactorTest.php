<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Util;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Util\LogLineRedactor;

class LogLineRedactorTest extends TestCase
{
    /**
     * @param string $line
     */
    private function redact(string $line): string
    {
        return (new LogLineRedactor())->redact($line);
    }

    public function testEmailsAreMasked(): void
    {
        $this->assertSame(
            'main.ERROR: Could not send order email to [email]',
            $this->redact('main.ERROR: Could not send order email to jane.doe@example.co.uk')
        );
    }

    public function testSecretLookingKeyValuesAreMasked(): void
    {
        $line = $this->redact('request {"username":"admin","password":"hunter2","api_key": "abc123"} token=xyz');

        $this->assertStringNotContainsString('hunter2', $line);
        $this->assertStringNotContainsString('abc123', $line);
        $this->assertStringNotContainsString('xyz', $line);
        $this->assertStringContainsString('"username":"admin"', $line);
    }

    public function testBearerTokensAreMasked(): void
    {
        $this->assertSame('Authorization: Bearer [redacted]', $this->redact('Authorization: Bearer eyJhbGciOi.payload.sig'));
    }

    public function testCardNumbersAreMaskedButOtherLongNumbersAreNot(): void
    {
        $this->assertSame('card [card] declined', $this->redact('card 4111 1111 1111 1111 declined'));
        $this->assertSame('order 1000000123456 shipped', $this->redact('order 1000000123456 shipped'));
    }

    public function testLongOpaqueTokensAreMasked(): void
    {
        $this->assertSame('session [token] expired', $this->redact('session 0123456789abcdef0123456789abcdef01 expired'));
    }

    public function testAnOrdinaryLineIsLeftReadable(): void
    {
        $line = '[2026-10-14T10:00:00+00:00] main.CRITICAL: Magento\\Framework\\Exception\\LocalizedException: Invalid cart in /var/www/html/app/code/Foo/Bar.php:42';

        $this->assertSame($line, $this->redact($line));
    }

    public function testIpv4AddressesAreMasked(): void
    {
        $this->assertSame('login from [ip] failed', $this->redact('login from 203.0.113.42 failed'));
    }

    public function testIpv6AddressesAreMaskedButClockTimesAndStaticCallsAreNot(): void
    {
        $this->assertSame('from [ip] ok', $this->redact('from 2001:db8:85a3::8a2e:370:7334 ok'));
        $this->assertSame('from [ip] ok', $this->redact('from ::1 ok'));
        $this->assertSame('at 12:30:45 Foo\\Bar::baz()', $this->redact('at 12:30:45 Foo\\Bar::baz()'));
    }

    public function testDuplicateEntryValuesAreMasked(): void
    {
        $this->assertSame(
            "SQLSTATE[23000]: Duplicate entry '[redacted]' for key 'CUSTOMER_ENTITY_EMAIL'",
            $this->redact("SQLSTATE[23000]: Duplicate entry 'jane doe@x' for key 'CUSTOMER_ENTITY_EMAIL'")
        );
    }
}
