<?php

declare(strict_types=1);

namespace HiEvents\Helper;

/**
 * Buyer phone typed at checkout, as E.164. Mayotte (+262) is the default: a 9-digit local number
 * such as 639 12 34 56 is read as 0639 12 34 56.
 */
final class OrderPhoneNormalizer
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (! str_starts_with($trimmed, '+') && ! str_starts_with($digits, '00') && strlen($digits) === 9) {
            $trimmed = '0'.$digits;
        }

        $phone = PhoneNumberNormalizer::normalize($trimmed, 'YT');

        return $phone !== null && strlen($phone) <= 20 ? $phone : null;
    }
}
