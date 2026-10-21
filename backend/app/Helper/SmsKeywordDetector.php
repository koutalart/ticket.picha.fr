<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use Illuminate\Support\Str;

final class SmsKeywordDetector
{
    public const STOP = 'STOP';

    public const START = 'START';

    private const STOP_KEYWORDS = ['STOP', 'STOP SMS', 'ARRET', 'ARRETER', 'UNSUBSCRIBE', 'DESINSCRIRE', 'DESABONNER'];

    private const START_KEYWORDS = ['START', 'UNSTOP'];

    public static function detect(string $content): ?string
    {
        $normalized = Str::upper(Str::ascii($content));
        $normalized = preg_replace('/[^A-Z ]+/', ' ', $normalized) ?? '';
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        if (in_array($normalized, self::STOP_KEYWORDS, true)) {
            return self::STOP;
        }

        if (in_array($normalized, self::START_KEYWORDS, true)) {
            return self::START;
        }

        return null;
    }
}
