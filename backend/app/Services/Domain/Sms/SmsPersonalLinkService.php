<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use Illuminate\Support\Collection;

/**
 * The tracked ticketing link already given to a contact, so that every link sent to the same person is counted for them.
 */
class SmsPersonalLinkService
{
    private const URL_PATTERN = '~https?://[^\s<>"«»]+~iu';

    public function __construct(
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly QrLinkUrlService $qrLinkUrlService,
        private readonly PublicBaseUrlResolver $publicBaseUrlResolver,
    ) {}

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     */
    public function findForContact(int $organizerId, string $phone, Collection $messages): ?string
    {
        $sentUrls = $this->sentUrls($messages);

        foreach ($this->campaignQrLinks($organizerId, $phone) as $qrLink) {
            $shortId = $qrLink->getShortId();
            $sent = $sentUrls->first(fn (string $url) => $this->qrParam($url) === strtolower($shortId));

            if ($sent !== null && ! $this->isPlatformUrlOfCustomDomainOrganizer($sent, $organizerId)) {
                return $sent;
            }

            return $this->qrLinkUrlService->getPublicUrl($qrLink);
        }

        return $sentUrls->first(fn (string $url) => $this->qrParam($url) !== null);
    }

    /**
     * Replaces a plain link to the same site as the personal link (no tracking) with the personal link.
     */
    public static function applyToReply(string $reply, ?string $personalLink): string
    {
        $host = $personalLink !== null ? self::host($personalLink) : null;
        if ($host === null) {
            return $reply;
        }

        return preg_replace_callback(self::URL_PATTERN, function (array $match) use ($host, $personalLink) {
            $url = rtrim($match[0], '.,;:!?)');
            $tail = substr($match[0], strlen($url));

            if (self::host($url) !== $host || str_contains($url, QrLinkUrlService::QR_QUERY_PARAM.'=')) {
                return $match[0];
            }

            return $personalLink.$tail;
        }, $reply) ?? $reply;
    }

    /**
     * @return Collection<int, QrLinkDomainObject>
     */
    private function campaignQrLinks(int $organizerId, string $phone): Collection
    {
        $qrLinkIds = $this->recipientRepository
            ->findWhere(
                where: [
                    SmsCampaignRecipientDomainObjectAbstract::PHONE => $phone,
                    [SmsCampaignRecipientDomainObjectAbstract::QR_LINK_ID, 'not null', null],
                ],
                orderAndDirections: [new OrderAndDirection(SmsCampaignRecipientDomainObjectAbstract::ID, 'desc')],
            )
            ->map(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getQrLinkId())
            ->values();

        if ($qrLinkIds->isEmpty()) {
            return collect();
        }

        $qrLinks = $this->qrLinkRepository
            ->findWhereIn(QrLinkDomainObjectAbstract::ID, $qrLinkIds->all(), [QrLinkDomainObjectAbstract::ORGANIZER_ID => $organizerId])
            ->keyBy(fn (QrLinkDomainObject $qrLink) => $qrLink->getId());

        return $qrLinkIds->map(fn (int $id) => $qrLinks->get($id))->filter()->values();
    }

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     * @return Collection<int, string>
     */
    private function sentUrls(Collection $messages): Collection
    {
        return $messages
            ->filter(fn (SmsMessageDomainObject $message) => $message->getDirection() === SmsMessageDirection::OUTBOUND->name)
            ->reverse()
            ->flatMap(function (SmsMessageDomainObject $message) {
                preg_match_all(self::URL_PATTERN, $message->getContent(), $matches);

                return array_map(fn (string $url) => rtrim($url, '.,;:!?)'), $matches[0]);
            })
            ->values();
    }

    private function isPlatformUrlOfCustomDomainOrganizer(string $url, int $organizerId): bool
    {
        $platformHost = self::host($this->publicBaseUrlResolver->platformUrl());
        $organizerHost = self::host($this->publicBaseUrlResolver->forOrganizerId($organizerId));

        return $organizerHost !== $platformHost && self::host($url) === $platformHost;
    }

    private function qrParam(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $value = $query[QrLinkUrlService::QR_QUERY_PARAM] ?? null;

        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    private static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }
}
