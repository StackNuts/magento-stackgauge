<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use DateTimeImmutable;
use DateTimeZone;
use StackNuts\StackGauge\Model\Util\Clock;

/**
 * Opens a direct TLS connection (with SNI) to $host:443 from this server - not from the
 * dashboard - and reads back the peer certificate's expiry, the same way a visitor's own
 * browser would see it. Checking from the store's own server rather than having the dashboard
 * poll it externally is deliberate, for the same reason Model\StorefrontProbe does: a dev
 * store behind a VPN or firewall that blocks the dashboard's own IP still gets a real answer.
 *
 * Every call makes verification its first attempt - the same peer/name checks a real browser
 * enforces, retried once so a transient network error isn't read as an untrusted certificate -
 * and only falls back to an unverified attempt if both fail, purely to
 * keep a self-signed or internally-issued certificate's expiry readable. That second attempt
 * always reports trusted=false regardless of what the certificate itself claims; it exists to
 * keep expiry visible on a dev/staging store, not to paper over the trust failure.
 */
class TlsCertificateInspector
{
    private const PORT = 443;
    private const TIMEOUT_SECONDS = 5.0;

    /**
     * The last connection error text from connectAndParse(), kept so a failed verification can
     * say why it failed.
     */
    protected string $lastConnectError = '';

    /**
     * @param Clock $clock
     */
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Inspects $host's certificate on port 443.
     *
     * "attempted" is always true here (unlike StorefrontProbe::probe()) - the caller already
     * decided $host is worth checking before calling this; see Reporter\UptimeReporter for
     * where that decision gets made.
     *
     * @param string $host
     * @return array{
     *     attempted: bool,
     *     connected: bool,
     *     trusted: bool,
     *     expires_at: string,
     *     days_remaining: int,
     *     issuer: string,
     *     subject: string,
     *     error: string|null
     * }
     */
    public function inspect(string $host): array
    {
        $this->lastConnectError = '';
        $parsed = $this->connectAndParse($host, verifyPeer: true);
        $trusted = true;

        if ($parsed === null) {
            // One more verified try before calling the certificate untrusted: a timeout or reset
            // on the first attempt looks identical to a failed verification, and would otherwise
            // raise a false "untrusted certificate" finding.
            $parsed = $this->connectAndParse($host, verifyPeer: true);
        }

        $verifyError = $this->lastConnectError;
        if ($parsed === null) {
            $trusted = false;
            $parsed = $this->connectAndParse($host, verifyPeer: false);
        }

        if ($parsed === null) {
            return $this->failure(
                sprintf('Could not connect to %s:%d or read its certificate.', $host, self::PORT)
            );
        }

        return $this->fromParsedCertificate($parsed, $trusted, $verifyError);
    }

    /**
     * Opens a TLS connection to $host:443 with SNI and returns openssl_x509_parse()'s result for
     * the peer certificate, or null on any connection or parse failure. Isolated in its own
     * method - rather than inlined in inspect() - purely so unit tests can substitute a fake
     * parsed certificate without a real socket; see this class's own unit test, which overrides
     * this method on an anonymous subclass.
     *
     * @param string $host
     * @param bool $verifyPeer
     * @return array<string,mixed>|null
     */
    protected function connectAndParse(string $host, bool $verifyPeer): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
            ],
        ]);

        $errorNumber = 0;
        $errorMessage = '';
        $socket = stream_socket_client(
            "ssl://{$host}:" . self::PORT,
            $errorNumber,
            $errorMessage,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            $this->lastConnectError = trim($errorMessage);
            return null;
        }

        try {
            $params = stream_context_get_params($socket);
            $certificateResource = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($certificateResource === null) {
                return null;
            }

            $parsed = openssl_x509_parse($certificateResource);

            return $parsed === false ? null : $parsed;
        } finally {
            fclose($socket);
        }
    }

    /**
     * Assembles inspect()'s return shape from a successful connection's parsed certificate.
     *
     * @param array<string,mixed> $parsed openssl_x509_parse()'s result for the peer certificate.
     * @param bool $trusted
     * @param string $verifyError Why the verified attempt failed, when $trusted is false.
     * @return array{
     *     attempted: bool,
     *     connected: bool,
     *     trusted: bool,
     *     expires_at: string,
     *     days_remaining: int,
     *     issuer: string,
     *     subject: string,
     *     error: string|null
     * }
     */
    private function fromParsedCertificate(array $parsed, bool $trusted, string $verifyError = ''): array
    {
        $expiresAtTimestamp = (int)($parsed['validTo_time_t'] ?? 0);
        $expiresAt = (new DateTimeImmutable('@' . $expiresAtTimestamp))->setTimezone(new DateTimeZone('UTC'));
        $secondsRemaining = $expiresAtTimestamp - $this->clock->now()->getTimestamp();

        return [
            'attempted' => true,
            'connected' => true,
            'trusted' => $trusted,
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'days_remaining' => (int)floor($secondsRemaining / 86400),
            'issuer' => $this->commonOrOrganizationName($parsed['issuer'] ?? []),
            'subject' => (string)(($parsed['subject'] ?? [])['CN'] ?? ''),
            'error' => $trusted
                ? null
                : 'Certificate failed verification' . ($verifyError !== '' ? ' (' . $verifyError . ')' : '')
                    . '; expiry was still read directly from the server.',
        ];
    }

    /**
     * Assembles inspect()'s return shape for a connection that never produced a certificate at all.
     *
     * @param string $error
     * @return array{
     *     attempted: bool,
     *     connected: bool,
     *     trusted: bool,
     *     expires_at: string,
     *     days_remaining: int,
     *     issuer: string,
     *     subject: string,
     *     error: string|null
     * }
     */
    private function failure(string $error): array
    {
        return [
            'attempted' => true,
            'connected' => false,
            'trusted' => false,
            'expires_at' => '',
            'days_remaining' => 0,
            'issuer' => '',
            'subject' => '',
            'error' => $error,
        ];
    }

    /**
     * A certificate name's CN if present, else its O, else '' - used for the issuer, which
     * commonly carries only an O (the certificate authority's organization) rather than a CN.
     *
     * @param array<string,mixed> $name
     */
    private function commonOrOrganizationName(array $name): string
    {
        return (string)($name['CN'] ?? $name['O'] ?? '');
    }
}
