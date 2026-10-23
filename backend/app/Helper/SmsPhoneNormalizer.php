<?php

declare(strict_types=1);

namespace HiEvents\Helper;

final class SmsPhoneNormalizer
{
    private const DOM_LOCAL_PREFIXES = ['639', '692', '693', '262', '269'];

    public static function normalize(string $phone): ?string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '' || strlen($digits) < 6 || strlen($digits) > 15) {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
            foreach (self::DOM_LOCAL_PREFIXES as $prefix) {
                if (str_starts_with($national, $prefix)) {
                    return '+262'.$national;
                }
            }

            return '+33'.$national;
        }

        if (strlen($digits) === 9 && (str_starts_with($digits, '6') || str_starts_with($digits, '7'))) {
            foreach (self::DOM_LOCAL_PREFIXES as $prefix) {
                if (str_starts_with($digits, $prefix)) {
                    return '+262'.$digits;
                }
            }

            return '+33'.$digits;
        }

        return '+'.$digits;
    }

    public static function matchingSuffix(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 9) {
            return null;
        }

        return substr($digits, -9);
    }
}
