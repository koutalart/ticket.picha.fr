<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

class OrderTrackingContextFactory
{
    private const FB_COOKIE_PATTERN = '/^fb\.\d\.\d{10,13}\.[A-Za-z0-9_\-.]{1,500}$/';

    public function create(?array $input, ?string $ipAddress, ?string $userAgent): ?array
    {
        if (($input['consent'] ?? false) !== true) {
            return null;
        }

        return array_filter([
            'consent' => true,
            'fbp' => $this->fbCookie($input['fbp'] ?? null),
            'fbc' => $this->fbCookie($input['fbc'] ?? null),
            'event_source_url' => $this->url($input['event_source_url'] ?? null),
            'client_ip_address' => filter_var($ipAddress, FILTER_VALIDATE_IP) ?: null,
            'client_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
        ], static fn ($value) => $value !== null);
    }

    private function fbCookie(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::FB_COOKIE_PATTERN, $value) ? $value : null;
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return null;
        }

        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) ? $value : null;
    }
}
