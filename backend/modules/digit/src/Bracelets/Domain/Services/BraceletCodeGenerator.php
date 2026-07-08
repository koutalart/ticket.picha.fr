<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Services;

/**
 * Pure, DB-independent code generation logic, extracted out of
 * BraceletGenerationService so it can be unit tested without any database
 * (see Digit\Tests\Bracelets\Unit\BraceletCodeGeneratorTest).
 *
 * The `code` is the only identifier printed on the bracelet - it is not a
 * secret (the HMAC signature is what makes a bracelet unforgeable, see
 * BraceletSignatureService), so this favors an unambiguous charset over
 * maximum entropy: no 0/O/1/I/L, easy for a human to read/type for the
 * rare manual-entry fallback.
 */
class BraceletCodeGenerator
{
    private const CODE_LENGTH = 14;

    /** Excludes 0/O, 1/I/L - avoids ambiguous manual entry/misreads. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function generate(): string
    {
        $random = '';
        $alphabetLength = strlen(self::CODE_ALPHABET);

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $random .= self::CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return 'BR' . $random . $this->checksum($random);
    }

    /**
     * Client-side (and here, server-side testable) reliability check: does
     * this code's embedded checksum match its random part? Purely a
     * scan-reliability aid for the future PWA scanner (Phase 3) to catch a
     * mangled/misread code before ever calling the API. NOT a security
     * mechanism - never confuse with HMAC signature verification.
     */
    public function isValidFormat(string $code): bool
    {
        if (!str_starts_with($code, 'BR')) {
            return false;
        }

        $body = substr($code, 2);

        if (strlen($body) !== self::CODE_LENGTH + 2) {
            return false;
        }

        $random = substr($body, 0, self::CODE_LENGTH);
        $checksum = substr($body, self::CODE_LENGTH);

        return $this->checksum($random) === $checksum;
    }

    /**
     * Non-cryptographic, 2-character checksum embedded in the code itself.
     */
    private function checksum(string $value): string
    {
        $sum = 0;

        foreach (str_split($value) as $char) {
            $sum = ($sum * 31 + ord($char)) % 961; // 31^2, keeps 2 base-31 digits
        }

        $alphabetLength = strlen(self::CODE_ALPHABET);
        $first = self::CODE_ALPHABET[intdiv($sum, $alphabetLength) % $alphabetLength];
        $second = self::CODE_ALPHABET[$sum % $alphabetLength];

        return $first . $second;
    }
}
