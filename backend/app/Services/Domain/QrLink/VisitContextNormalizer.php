<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

class VisitContextNormalizer
{
    public static function countryCode(?string $countryCode): ?string
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        return preg_match('/^[A-Z]{2}$/', $countryCode) && $countryCode !== 'XX' ? $countryCode : null;
    }

    public static function referrerHost(?string $referrer): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        return $host ? substr(strtolower(preg_replace('/^www\./', '', $host)), 0, 255) : null;
    }
}
