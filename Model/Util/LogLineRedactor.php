<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Util;

/**
 * Masks the obviously sensitive parts of a log line before LogReporter sends it off-site.
 * Magento and third-party modules routinely log request payloads, customer emails, API
 * responses and the odd credential - none of which should leave the store just because the
 * dashboard shows a log tail. Best-effort pattern matching, not a guarantee: it targets the
 * common shapes (emails, key=value secrets, bearer tokens, long opaque tokens, card numbers)
 * while leaving the line readable enough to diagnose from.
 */
class LogLineRedactor
{
    private const SECRET_KEYS = 'password|passwd|pwd|pass|secret|token|api[_-]?key|access[_-]?key|auth|'
        . 'authorization|private[_-]?key|client[_-]?secret|cvv|cvc|cc[_-]?number|card[_-]?number';

    /**
     * Redacts $line - see this class's own docblock for what's covered.
     *
     * @param string $line
     */
    public function redact(string $line): string
    {
        $line = (string)preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $line);

        // key=value / "key": "value" / key: value, for any secret-looking key - skipping an
        // "Authorization: Bearer [redacted]" the line above already handled.
        $line = (string)preg_replace(
            '/(\b(?:' . self::SECRET_KEYS . ')["\']?\s*(?:=>|[=:])\s*["\']?)(?!(?:Bearer|Basic)\b)[^\s"\'&,;}\]]+/i',
            '$1[redacted]',
            $line
        );

        $line = (string)preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $line);

        $line = (string)preg_replace_callback(
            '/\b(?:\d[ -]?){12,18}\d\b/',
            fn(array $match): string => $this->passesLuhn($match[0]) ? '[card]' : $match[0],
            $line
        );

        // Long opaque tokens (session ids, API keys, JWT segments) - 32+ chars with no
        // separators a class name, path or sentence would have.
        return (string)preg_replace('/\b[A-Za-z0-9_-]{32,}\b/', '[token]', $line);
    }

    /**
     * Whether $candidate's digits pass the Luhn checksum - keeps order numbers, timestamps
     * and other long digit runs from being mistaken for card numbers.
     *
     * @param string $candidate
     */
    private function passesLuhn(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate);
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int)$digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
