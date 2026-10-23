<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event;

use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;

class HomepageFooterSettingsSanitizer
{
    public const MAX_EVENT_LINKS = 4;
    public const MAX_RELATED_LINKS = 3;
    public const URL_PATTERN = '/^(https?:\/\/[^\s]+|\/[^\s]*|#[\w-]*)$/i';

    public function __construct(
        private readonly HtmlPurifierService $purifier,
    )
    {
    }

    public function sanitize(array|string|null $settings): ?array
    {
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        if (!is_array($settings)) {
            return null;
        }

        return [
            'newsletter_enabled' => (bool)($settings['newsletter_enabled'] ?? true),
            'newsletter_title' => $this->text($settings['newsletter_title'] ?? null),
            'privacy_policy_url' => $this->url($settings['privacy_policy_url'] ?? null),
            'terms_url' => $this->url($settings['terms_url'] ?? null),
            'accessibility_url' => $this->url($settings['accessibility_url'] ?? null),
            'event_links' => $this->links(
                $settings['event_links'] ?? [],
                self::MAX_EVENT_LINKS,
                fn(array $link) => [
                    'label' => $this->text($link['label'] ?? null),
                    'url' => $this->url($link['url'] ?? null),
                ],
                'label',
            ),
            'related_links' => $this->links(
                $settings['related_links'] ?? [],
                self::MAX_RELATED_LINKS,
                fn(array $link) => [
                    'title' => $this->text($link['title'] ?? null),
                    'url' => $this->url($link['url'] ?? null),
                    'image_url' => $this->url($link['image_url'] ?? null),
                ],
                'title',
            ),
        ];
    }

    private function links(mixed $links, int $max, callable $map, string $labelKey): array
    {
        if (!is_array($links)) {
            return [];
        }

        return collect($links)
            ->filter(fn($link) => is_array($link))
            ->map($map)
            ->filter(fn(array $link) => $link[$labelKey] !== null && $link['url'] !== null)
            ->take($max)
            ->values()
            ->all();
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim(html_entity_decode(strip_tags((string)$this->purifier->purify($value)), ENT_QUOTES | ENT_HTML5));

        return $clean === '' ? null : $clean;
    }

    private function url(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || !preg_match(self::URL_PATTERN, $value)) {
            return null;
        }

        return $value;
    }
}
