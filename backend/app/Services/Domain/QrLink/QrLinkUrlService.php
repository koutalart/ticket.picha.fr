<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;

class QrLinkUrlService
{
    public const QR_QUERY_PARAM = 'qr';

    public function getPublicUrl(QrLinkDomainObject $qrLink): string
    {
        return $this->frontendUrl('/q/'.$qrLink->getShortId());
    }

    public function getEventUrl(EventDomainObject $event, QrLinkDomainObject $qrLink): string
    {
        return $event->getEventUrl().'?'.http_build_query([self::QR_QUERY_PARAM => $qrLink->getShortId()]);
    }

    public function getRedirectUrl(QrLinkDomainObject $qrLink, ?EventDomainObject $event): ?string
    {
        return match (QrLinkDestinationType::from($qrLink->getDestinationType())) {
            QrLinkDestinationType::URL => $qrLink->getDestinationUrl(),
            QrLinkDestinationType::EVENT => $event ? $this->getEventUrl($event, $qrLink) : null,
            QrLinkDestinationType::LINK_PAGE => null,
        };
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
