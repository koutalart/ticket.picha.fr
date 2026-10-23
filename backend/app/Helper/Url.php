<?php

namespace HiEvents\Helper;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;

class Url
{
    public const STRIPE_CONNECT_RETURN_URL = 'app.frontend_urls.stripe_connect_return_url';
    public const STRIPE_CONNECT_REFRESH_URL = 'app.frontend_urls.stripe_connect_refresh_url';
    public const RESET_PASSWORD = 'app.frontend_urls.reset_password';
    public const CONFIRM_EMAIL_CHANGE = 'app.frontend_urls.confirm_email_change';
    public const ACCEPT_INVITATION = 'app.frontend_urls.accept_invitation';
    public const CONFIRM_EMAIL_ADDRESS = 'app.frontend_urls.confirm_email_address';
    public const EVENT_HOMEPAGE = 'app.frontend_urls.event_homepage';
    public const ATTENDEE_TICKET = 'app.frontend_urls.attendee_product';
    public const ATTENDEE_ATTENDANCE = 'app.frontend_urls.attendee_attendance';
    public const ORDER_SUMMARY = 'app.frontend_urls.order_summary';
    public const ORDER_DETAILS = 'app.frontend_urls.order_details';
    public const ORGANIZER_ORDER_SUMMARY = 'app.frontend_urls.organizer_order_summary';
    public const TICKET_LOOKUP = 'app.frontend_urls.ticket_lookup';

    public static function getFrontEndUrlFromConfig(string $key, array $queryParams = [], ?string $baseUrl = null): string
    {
        $url = ($baseUrl ?? config('app.frontend_url')) . config($key);

        return self::addQueryParamsToUrl($queryParams, $url);
    }

    /**
     * A participant-facing URL, on the organizer's custom domain when it has one.
     */
    public static function getPublicFrontEndUrlFromConfig(
        string                                       $key,
        EventDomainObject|OrganizerDomainObject|null $owner,
        array                                        $queryParams = [],
    ): string
    {
        return self::getFrontEndUrlFromConfig($key, $queryParams, self::publicBaseUrl($owner));
    }

    public static function getPublicFrontEndUrlForEventId(string $key, ?int $eventId, array $queryParams = []): string
    {
        return self::getFrontEndUrlFromConfig($key, $queryParams, app(PublicBaseUrlResolver::class)->forEventId($eventId));
    }

    public static function publicBaseUrl(EventDomainObject|OrganizerDomainObject|null $owner): string
    {
        $resolver = app(PublicBaseUrlResolver::class);

        return $owner instanceof OrganizerDomainObject
            ? $resolver->forOrganizer($owner)
            : $resolver->forEvent($owner);
    }

    public static function getApiUrl(string $path, array $queryParams = []): string
    {
        $url = rtrim(config('app.api_url'), '/') . '/' . ltrim($path, '/');

        return self::addQueryParamsToUrl($queryParams, $url);
    }

    /**
    * Generates a CDN URL for the given path if a CDN URL is configured.
    * Falls back to generating a URL using the specified or default filesystem disk.
    *
    * @param string $path The relative path to the asset.
    * @return string The fully qualified URL to the asset, either via CDN or storage disk.
    */
    public static function getCdnUrl(string $path): string
    {
        // No default value here: without APP_CDN_URL, the storage disk URL is used.
        $envCDNUrl = config('app.cdn_url_override');

        if ($envCDNUrl) {
            return  $envCDNUrl . '/' . $path;
         }

        $disk = config('filesystems.public', 'public');
        return app('filesystem')->disk($disk)->url($path);
    }

    private static function addQueryParamsToUrl(array $queryParams, mixed $url): mixed
    {
        if (!empty($queryParams)) {
            $query = http_build_query($queryParams);
            $url = rtrim($url, '/') . '?' . $query;
        }

        return $url;
    }
}
