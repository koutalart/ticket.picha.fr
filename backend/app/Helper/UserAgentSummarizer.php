<?php

declare(strict_types=1);

namespace HiEvents\Helper;

final class UserAgentSummarizer
{
    private const BROWSERS = [
        'Instagram' => 'Instagram',
        'FBAN|FBAV|FB_IAB' => 'Facebook',
        'Snapchat' => 'Snapchat',
        'TikTok|musical_ly|BytedanceWebview' => 'TikTok',
        'WhatsApp' => 'WhatsApp',
        'SamsungBrowser' => 'Samsung',
        'Edg\/|EdgA\/|EdgiOS' => 'Edge',
        'OPR\/|Opera' => 'Opera',
        'Firefox\/|FxiOS' => 'Firefox',
        'CriOS|Chrome\/' => 'Chrome',
        'Safari\/' => 'Safari',
        'curl|Wget|python|Go-http|okhttp' => 'Script',
    ];

    private const SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Macintosh|Mac OS X' => 'Mac',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function summarize(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return '?';
        }

        $browser = self::match($userAgent, self::BROWSERS);
        $system = self::match($userAgent, self::SYSTEMS);

        $summary = trim(($browser ?? '').' '.($system ?? ''));

        return $summary === '' ? mb_substr($userAgent, 0, 24) : $summary;
    }

    private static function match(string $userAgent, array $patterns): ?string
    {
        foreach ($patterns as $pattern => $label) {
            if (preg_match('/'.$pattern.'/i', $userAgent) === 1) {
                return $label;
            }
        }

        return null;
    }
}
