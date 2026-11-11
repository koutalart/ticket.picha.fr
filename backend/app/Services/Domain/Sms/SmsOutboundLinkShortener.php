<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\Services\Domain\Mail\OutboundLinkShortener;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every SMS leaves without a long link to our sites: each one becomes the organizer's tracked /q/ link.
 */
class SmsOutboundLinkShortener
{
    public function __construct(
        private readonly OutboundLinkShortener $shortener,
        private readonly SmsShortLinkService $shortLinkService,
        private readonly PublicBaseUrlResolver $publicBaseUrlResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function shorten(int $accountId, int $organizerId, string $content): string
    {
        if (! str_contains($content, 'http')) {
            return $content;
        }

        try {
            $hosts = array_unique(array_filter([
                parse_url($this->publicBaseUrlResolver->platformUrl(), PHP_URL_HOST),
                parse_url($this->publicBaseUrlResolver->forOrganizerId($organizerId), PHP_URL_HOST),
            ]));

            return $this->shortener->rewrite(
                $content,
                $hosts,
                fn (string $url): string => $this->shortLinkService->shortenFor($accountId, $organizerId, null, '', $url),
                false,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('SMS sent with its long link: short link failed', [
                'organizer_id' => $organizerId,
                'error' => $exception->getMessage(),
            ]);

            return $content;
        }
    }
}
