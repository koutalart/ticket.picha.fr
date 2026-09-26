<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use HiEvents\Exceptions\MissingPhoneCallingCodeException;

final class PhoneNormalizer
{
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

        $codeDigits = preg_replace('/\D+/', '', $callingCode) ?? '';

        if ($codeDigits === '') {
            throw new MissingPhoneCallingCodeException(
                __('Indicatif téléphone manquant — renseignez-le dans Réglages du poste.')
            );
        }

        $local = $digits;
        if (str_starts_with($local, '0')) {
            $local = substr($local, 1);
        }

        return '+'.$codeDigits.$local;
    }
}
