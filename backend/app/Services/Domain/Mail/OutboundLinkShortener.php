<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Mail;

/**
 * Replaces every long link to our public sites (platform or organizer domain) found in a message body with its short link.
 * Images, stylesheets, stored files and links that are already short are left untouched.
 */
class OutboundLinkShortener
{
    private const URL_PATTERN = '~https?://[^\s"\'<>()]+~i';

    private const TRAILING_PUNCTUATION = '.,;:!?';

    private const ASSET_EXTENSIONS = '~\.(png|jpe?g|gif|webp|svg|ico|css|js|woff2?|ttf|mp4|webm)$~i';

    /**
     * @param  string[]  $hosts
     * @param  callable(string): string  $shorten  long URL to short URL
     */
    public function rewrite(string $body, array $hosts, callable $shorten, bool $isHtml): string
    {
        $hosts = array_values(array_filter(array_map(static fn ($host) => strtolower((string) $host), $hosts)));
        if ($hosts === [] || $body === '') {
            return $body;
        }

        $replacements = [];

        preg_match_all(self::URL_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$raw, $offset]) {
            $raw = rtrim($raw, self::TRAILING_PUNCTUATION);
            if (isset($replacements[$raw]) || $this->isEmbeddedResource($body, $offset)) {
                continue;
            }

            $url = $isHtml ? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $raw;
            if (! $this->isLongPublicLink($url, $hosts)) {
                continue;
            }

            $short = $shorten($url);
            if ($short !== $url) {
                $replacements[$raw] = $isHtml ? htmlspecialchars($short, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $short;
            }
        }

        if ($replacements === []) {
            return $body;
        }

        uksort($replacements, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return strtr($body, $replacements);
    }

    /**
     * @param  string[]  $hosts
     */
    public function isLongPublicLink(string $url, array $hosts): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['host']), $hosts, true)) {
            return false;
        }

        $path = $parts['path'] ?? '';
        if (($path === '' || $path === '/') && ! isset($parts['query'])) {
            return false;
        }

        return ! str_starts_with($path, '/q/')
            && ! str_starts_with($path, '/api/storage/')
            && ! str_starts_with($path, '/storage/')
            && ! str_starts_with($path, '/manifest-icons/')
            && preg_match(self::ASSET_EXTENSIONS, $path) !== 1;
    }

    private function isEmbeddedResource(string $body, int $offset): bool
    {
        $before = strtolower(substr($body, max(0, $offset - 12), min(12, $offset)));

        return preg_match('~(src|background|srcset|poster)\s*=\s*["\']?$|url\(\s*["\']?$~', $before) === 1;
    }
}
