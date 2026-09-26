<?php

declare(strict_types=1);

namespace HiEvents\Helper;

final class KioskSentinelEmail
{
    public const DOMAIN = 'no-mail.picha.invalid';

    public static function isKioskSentinelEmail(?string $email): bool
    {
        if ($email === null || $email === '') {
            return true;
        }

        return str_ends_with(strtolower($email), '@'.self::DOMAIN);
    }

    public static function forBoxOfficeSale(int $saleId): string
    {
        return 'kiosk.s'.$saleId.'@'.self::DOMAIN;
    }
}
