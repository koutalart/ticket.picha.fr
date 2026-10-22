<?php

declare(strict_types=1);

namespace HiEvents\Helper;

final class IpAddressMasker
{
    public static function mask(string $ipAddress): string
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ipAddress);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ipAddress);
            if ($packed !== false) {
                return (string) inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));
            }
        }

        return 'unknown';
    }
}
