<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Builds the tracking context saved on an order from what the public pages send.
 * The client payload is never trusted: only whitelisted keys are kept, every value is validated
 * and length limited, and advertising identifiers are kept only with the marketing consent.
 */
class OrderTrackingContextFactory
{
    public const CONSENT_CATEGORY_NECESSARY = 'necessary';

    public const CONSENT_CATEGORY_AUDIENCE = 'audience';

    public const CONSENT_CATEGORY_MARKETING = 'marketing';

    private const CONSENT_CATEGORIES = [
        self::CONSENT_CATEGORY_NECESSARY,
        self::CONSENT_CATEGORY_AUDIENCE,
        self::CONSENT_CATEGORY_MARKETING,
    ];

    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    private const CLICK_ID_KEYS = ['gclid', 'ttclid', 'wbraid', 'gbraid', 'fbclid'];

    private const FB_COOKIE_PATTERN = '/^fb\.\d\.\d{10,13}\.[A-Za-z0-9_\-.]{1,500}$/';

    private const GCL_AW_PATTERN = '/^GCL\.\d{10}\.[A-Za-z0-9_\-.]{1,500}$/';

    private const CLICK_ID_PATTERN = '/^[A-Za-z0-9_\-.]{1,500}$/';

    private const QR_CODE_PATTERN = '/^[a-z0-9]{4,32}$/';

    private const DOMAIN_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    private const UTM_MAX_LENGTH = 150;

    private const PATH_MAX_LENGTH = 512;

    private const URL_MAX_LENGTH = 2048;

    public function create(?array $input, ?string $ipAddress, ?string $userAgent, ?string $qrCode = null): ?array
    {
        $input ??= [];
        $consentDetails = $this->consentDetails($input['consent_details'] ?? null);
        $marketing = ($input['consent'] ?? false) === true
            && ($consentDetails === null || in_array(self::CONSENT_CATEGORY_MARKETING, $consentDetails['categories'], true));
        $audience = $marketing || $consentDetails === null
            || in_array(self::CONSENT_CATEGORY_AUDIENCE, $consentDetails['categories'], true);

        $context = [
            'consent' => $marketing,
            'consent_details' => $consentDetails,
            'qr_code' => $this->qrCode($qrCode),
        ];

        if ($audience) {
            $context['first_touch'] = $this->touch($input['first_touch'] ?? null, $marketing);
            $context['last_touch'] = $this->touch($input['last_touch'] ?? null, $marketing);
        }

        if ($marketing) {
            $context += [
                'fbp' => $this->match($input['fbp'] ?? null, self::FB_COOKIE_PATTERN),
                'fbc' => $this->match($input['fbc'] ?? null, self::FB_COOKIE_PATTERN),
                'gcl_aw' => $this->match($input['gcl_aw'] ?? null, self::GCL_AW_PATTERN),
                'event_source_url' => $this->url($input['event_source_url'] ?? null),
                'client_ip_address' => filter_var($ipAddress, FILTER_VALIDATE_IP) ?: null,
                'client_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            ];

            foreach (self::CLICK_ID_KEYS as $key) {
                $context[$key] = $this->match($input[$key] ?? null, self::CLICK_ID_PATTERN);
            }
        }

        $context = array_filter($context, static fn ($value) => $value !== null && $value !== []);

        return array_keys($context) === ['consent'] && $context['consent'] === false ? null : $context;
    }

    private function consentDetails(mixed $value): ?array
    {
        if (! is_array($value) || ! is_array($value['categories'] ?? null)) {
            return null;
        }

        $categories = array_values(array_intersect(self::CONSENT_CATEGORIES, $value['categories']));
        $version = is_int($value['version'] ?? null) && $value['version'] > 0 && $value['version'] < 1000
            ? $value['version']
            : null;

        return [
            'categories' => $categories,
            'version' => $version,
            'timestamp' => $this->timestamp($value['timestamp'] ?? null),
        ];
    }

    private function touch(mixed $value, bool $marketing): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $touch = [];
        foreach (self::UTM_KEYS as $key) {
            $touch[$key] = $this->text($value[$key] ?? null, self::UTM_MAX_LENGTH);
        }

        $touch['referrer_domain'] = $this->domain($value['referrer_domain'] ?? null);
        $touch['landing_path'] = $this->path($value['landing_path'] ?? null);

        if ($marketing) {
            $touch['referrer'] = $this->url($value['referrer'] ?? null);
            $touch['landing_url'] = $this->url($value['landing_url'] ?? null);
        }

        $touch = array_filter($touch, static fn ($item) => $item !== null);
        if ($touch === []) {
            return null;
        }

        $touch['captured_at'] = $this->timestamp($value['captured_at'] ?? null);

        return array_filter($touch, static fn ($item) => $item !== null);
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F<>"\'`]/u', '', (string) $value));

        return $clean === '' ? null : mb_substr($clean, 0, $maxLength);
    }

    private function domain(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 253) {
            return null;
        }

        $domain = preg_replace('/^www\./', '', strtolower(trim($value)));

        return preg_match(self::DOMAIN_PATTERN, $domain) ? $domain : null;
    }

    private function path(mixed $value): ?string
    {
        if (! is_string($value) || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        $path = (string) parse_url($value, PHP_URL_PATH);
        if ($path === '' || preg_match('/[\x00-\x1F\x7F<>"\'`\s]/', $path)) {
            return null;
        }

        return mb_substr($path, 0, self::PATH_MAX_LENGTH);
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > self::URL_MAX_LENGTH || preg_match('/[\x00-\x1F\x7F<>"\'`\s]/', $value)) {
            return null;
        }

        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)
            && parse_url($value, PHP_URL_HOST)
            ? $value
            : null;
    }

    private function match(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) ? $value : null;
    }

    private function qrCode(?string $value): ?string
    {
        $code = $value === null ? null : strtolower(trim($value));

        return $code !== null && preg_match(self::QR_CODE_PATTERN, $code) ? $code : null;
    }

    private function timestamp(mixed $value): ?string
    {
        try {
            $date = match (true) {
                is_int($value) => CarbonImmutable::createFromTimestampMs($value),
                is_string($value) && strlen($value) <= 40 => CarbonImmutable::parse($value),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($date === null || $date->isAfter(CarbonImmutable::now()->addDay()) || $date->isBefore(CarbonImmutable::now()->subYears(2))) {
            return null;
        }

        return $date->utc()->toIso8601ZuluString();
    }
}
