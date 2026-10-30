<?php

declare(strict_types=1);

namespace HiEvents\Helper;

final class SmsPhoneNormalizer
{
    private const DOM_LOCAL_PREFIXES = ['639', '692', '693', '262', '263', '269'];

    public static function normalize(string $phone): ?string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '' || strlen($digits) < 6 || strlen($digits) > 15) {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            return self::overseasFromFrench('+'.$digits);
        }

        if (str_starts_with($digits, '00')) {
            return self::overseasFromFrench('+'.substr($digits, 2));
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

    /**
     * 0639, 0692, 0693, 0262, 0263 and 0269 are numbers of Mayotte and La Réunion: written with +33 (French phone
     * inputs, forms with France as default country), they are rewritten with +262, the only way they can be reached.
     */
    public static function overseasFromFrench(string $e164): string
    {
        if (! preg_match('/^\+33(\d{9})$/', $e164, $matches)) {
            return $e164;
        }

        foreach (self::DOM_LOCAL_PREFIXES as $prefix) {
            if (str_starts_with($matches[1], $prefix)) {
                return '+262'.$matches[1];
            }
        }

        return $e164;
    }

    /**
     * The number and, for a Mayotte or La Réunion number, the same number written with +33, as rows saved before
     * that rewrite may still hold it.
     *
     * @return array<int, string>
     */
    public static function equivalents(string $e164): array
    {
        if (! preg_match('/^\+262(\d{9})$/', $e164, $matches) || self::overseasFromFrench('+33'.$matches[1]) !== $e164) {
            return [$e164];
        }

        return [$e164, '+33'.$matches[1]];
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
