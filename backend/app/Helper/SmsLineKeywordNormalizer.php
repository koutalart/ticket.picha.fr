<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use Illuminate\Support\Str;

/**
 * Keywords of a shared SMS line compare without case, accents nor punctuation: « Jungle! », « jungle » and « JÜNGLE »
 * are all JUNGLE.
 */
final class SmsLineKeywordNormalizer
{
    /**
     * The words of the text, upper case, accents removed, anything else than letters and digits used as a separator.
     *
     * @return array<int, string>
     */
    public static function words(string $text): array
    {
        $normalized = Str::upper(Str::ascii($text));
        $normalized = preg_replace('/[^A-Z0-9]+/', ' ', $normalized) ?? '';

        return preg_split('/\s+/', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function firstWord(string $content): ?string
    {
        return self::words($content)[0] ?? null;
    }
}
