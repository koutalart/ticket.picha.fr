<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use HiEvents\Services\Domain\QrLink\DTO\ParsedUserAgentDTO;

class UserAgentParser
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|facebookcatalog|meta-externalagent|whatsapp|telegram|discord|slack|skype|preview|embedly|vkshare|linkedin|pinterest|redditbot|headless|lighthouse|curl|wget|python-requests|go-http-client|axios|node-fetch|okhttp|java\//i';

    public function parse(?string $userAgent): ParsedUserAgentDTO
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent)) {
            return new ParsedUserAgentDTO(isBot: true, deviceType: null, os: null, browser: null);
        }

        return new ParsedUserAgentDTO(
            isBot: false,
            deviceType: $this->detectDeviceType($userAgent),
            os: $this->detectOs($userAgent),
            browser: $this->detectBrowser($userAgent),
        );
    }

    private function detectDeviceType(string $userAgent): string
    {
        if (preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/mobi|iphone|ipod|android|windows phone|blackberry|opera mini/i', $userAgent)) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function detectOs(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/iphone|ipad|ipod/i', $userAgent) => 'iOS',
            (bool) preg_match('/android/i', $userAgent) => 'Android',
            (bool) preg_match('/windows/i', $userAgent) => 'Windows',
            (bool) preg_match('/mac os x|macintosh/i', $userAgent) => 'macOS',
            (bool) preg_match('/cros/i', $userAgent) => 'ChromeOS',
            (bool) preg_match('/linux/i', $userAgent) => 'Linux',
            default => 'other',
        };
    }

    private function detectBrowser(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/instagram/i', $userAgent) => 'Instagram',
            (bool) preg_match('/fban|fbav|fb_iab/i', $userAgent) => 'Facebook',
            (bool) preg_match('/tiktok|musical_ly|bytedance/i', $userAgent) => 'TikTok',
            (bool) preg_match('/snapchat/i', $userAgent) => 'Snapchat',
            (bool) preg_match('/samsungbrowser/i', $userAgent) => 'Samsung',
            (bool) preg_match('/edg\//i', $userAgent) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $userAgent) => 'Opera',
            (bool) preg_match('/firefox|fxios/i', $userAgent) => 'Firefox',
            (bool) preg_match('/chrome|crios/i', $userAgent) => 'Chrome',
            (bool) preg_match('/safari/i', $userAgent) => 'Safari',
            default => 'other',
        };
    }
}
