<?php

declare(strict_types=1);

namespace HiEvents\Helper;

final class SmsSegmentCounter
{
    private const GSM_7BIT = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_7BIT_EXTENDED = '^{}\\[~]|€';

    public static function count(string $text): int
    {
        $chars = mb_str_split($text);
        if ($chars === []) {
            return 0;
        }

        $unicode = false;
        $units = 0;
        foreach ($chars as $char) {
            if (str_contains(self::GSM_7BIT_EXTENDED, $char)) {
                $units += 2;
            } elseif (str_contains(self::GSM_7BIT, $char)) {
                $units++;
            } else {
                $unicode = true;
            }
        }

        if ($unicode) {
            $units = count($chars);
        }

        $single = $unicode ? 70 : 160;
        $multi = $unicode ? 67 : 153;

        return $units <= $single ? 1 : (int) ceil($units / $multi);
    }
}
