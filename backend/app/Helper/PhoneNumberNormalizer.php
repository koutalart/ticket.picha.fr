<?php

namespace HiEvents\Helper;

/**
 * Converts the phone formats PICHA customers type (France, Mayotte, La Réunion,
 * Madagascar, Denmark) into E.164. Returns null when the number is not usable.
 */
class PhoneNumberNormalizer
{
    private const COUNTRY_CODES = [
        'FR' => '33',
        'YT' => '262',
        'RE' => '262',
        'MG' => '261',
        'DK' => '45',
    ];

    private const REUNION_MAYOTTE_PREFIXES = ['262', '263', '269', '639', '692', '693'];

    public static function normalize(?string $raw, string $defaultCountry = 'FR'): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        $hasPlus = str_starts_with(ltrim($raw), '+');

        if ($digits === '') {
            return null;
        }

        if (!$hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if ($hasPlus) {
            return self::validInternational($digits);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $national = substr($digits, 1);

            if (in_array(substr($national, 0, 3), self::REUNION_MAYOTTE_PREFIXES, true)) {
                return '+262' . $national;
            }

            $country = self::COUNTRY_CODES[strtoupper($defaultCountry)] ?? '33';

            return '+' . ($country === '262' ? '33' : $country) . $national;
        }

        if (strtoupper($defaultCountry) === 'DK' && strlen($digits) === 8) {
            return '+45' . $digits;
        }

        return self::validInternational($digits);
    }

    private static function validInternational(string $digits): ?string
    {
        $length = strlen($digits);

        if ($length < 8 || $length > 15 || str_starts_with($digits, '0')) {
            return null;
        }

        return '+' . $digits;
    }
}
