<?php

declare(strict_types=1);

namespace HiEvents\Helper;

class SmsTextSanitizer
{
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $text) ?? '';
        $text = preg_replace('/<\/?[a-z][a-z0-9-]*(\s[^<>]*)?\/?>/i', '', $text) ?? '';
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';

        return trim($text);
    }
}
