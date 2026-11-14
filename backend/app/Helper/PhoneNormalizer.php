<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use HiEvents\Exceptions\MissingPhoneCallingCodeException;

final class PhoneNormalizer
{
    private const OVERSEAS_262_PREFIXES = ['639', '693', '692', '262', '269'];

    public static function normalize(string $phone, string $callingCode): ?string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00') && strlen($digits) > 4) {
            return '+'.substr($digits, 2);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '262')) {
            return '+'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '33')) {
            return '+'.$digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $local = substr($digits, 1);

            return in_array(substr($local, 0, 3), self::OVERSEAS_262_PREFIXES, true)
                ? '+262'.$local
                : '+33'.$local;
        }

        if (strlen($digits) === 9 && in_array(substr($digits, 0, 3), self::OVERSEAS_262_PREFIXES, true)) {
            return '+262'.$digits;
        }

        $codeDigits = preg_replace('/\D+/', '', $callingCode) ?? '';

        if ($codeDigits === '') {
            if (strlen($digits) === 9 && in_array($digits[0], ['6', '7'], true)) {
                return '+33'.$digits;
            }

            throw new MissingPhoneCallingCodeException(
                __('Indicatif téléphone manquant — renseignez-le dans Réglages du poste.')
            );
        }

        $local = str_starts_with($digits, '0') ? substr($digits, 1) : $digits;

        return '+'.$codeDigits.$local;
    }
}
