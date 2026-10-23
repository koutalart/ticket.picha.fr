<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;

class QrLinkUrlService
{
    public const QR_QUERY_PARAM = 'qr';

    public const QR_COUNTED_PARAM = 'qrc';

    public function getPublicUrl(QrLinkDomainObject $qrLink): string
    {
        return app(PublicBaseUrlResolver::class)->forOrganizerId($qrLink->getOrganizerId()).'/q/'.$qrLink->getShortId();
    }

    public function getEventUrl(EventDomainObject $event, QrLinkDomainObject $qrLink): string
    {
        return $event->getEventUrl().'?'.http_build_query([
            self::QR_QUERY_PARAM => $qrLink->getShortId(),
            self::QR_COUNTED_PARAM => 1,
        ]);
    }

    public function getRedirectUrl(QrLinkDomainObject $qrLink, ?EventDomainObject $event, ?string $os = null): ?string
    {
        return match (QrLinkDestinationType::from($qrLink->getDestinationType())) {
            QrLinkDestinationType::URL => $qrLink->getDestinationUrl(),
            QrLinkDestinationType::EVENT => $event ? $this->getEventUrl($event, $qrLink) : null,
            QrLinkDestinationType::APP => $this->getAppRedirectUrl($qrLink, $os),
            QrLinkDestinationType::LINK_PAGE => null,
        };
    }

    private function getAppRedirectUrl(QrLinkDomainObject $qrLink, ?string $os): ?string
    {
        return match ($os) {
            'iOS' => $qrLink->getAppStoreUrl(),
            'Android' => $qrLink->getPlayStoreUrl(),
            default => $qrLink->getDestinationUrl() ?: $qrLink->getAppStoreUrl(),
        };
    }
}
