<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Domain\QrLink\QrLinkShortIdGenerator;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns the long link of an automatic SMS (attendance page, registration page) into a tracked /q/ link of the organizer.
 * The same long link always gets the same short link. These links are kept out of the organizer's QR code list.
 */
class SmsShortLinkService
{
    private const NAME_MAX_LENGTH = 120;

    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly QrLinkShortIdGenerator $shortIdGenerator,
        private readonly QrLinkUrlService $qrLinkUrlService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Falls back to the long link when the short one cannot be made, so the person still gets a working link.
     */
    public function shorten(EventDomainObject $event, string $url): string
    {
        try {
            return $this->qrLinkUrlService->getPublicUrl($this->findOrCreate($event, $url));
        } catch (Throwable $exception) {
            $this->logger->warning('Automatic SMS: short link not created, long link sent', [
                'event_id' => $event->getId(),
                'error' => $exception->getMessage(),
            ]);

            return $url;
        }
    }

    private function findOrCreate(EventDomainObject $event, string $url): QrLinkDomainObject
    {
        /** @var QrLinkDomainObject|null $existing */
        $existing = $this->qrLinkRepository->findFirstWhere([
            QrLinkDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
            QrLinkDomainObjectAbstract::IS_AUTOMATIC_SMS_LINK => true,
            QrLinkDomainObjectAbstract::DESTINATION_URL => $url,
        ]);

        if ($existing !== null) {
            return $existing;
        }

        return $this->qrLinkRepository->create([
            QrLinkDomainObjectAbstract::ACCOUNT_ID => $event->getAccountId(),
            QrLinkDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
            QrLinkDomainObjectAbstract::EVENT_ID => $event->getId(),
            QrLinkDomainObjectAbstract::SHORT_ID => $this->shortIdGenerator->generate(),
            QrLinkDomainObjectAbstract::NAME => mb_substr(__('Automatic SMS link').' '.$event->getTitle(), 0, self::NAME_MAX_LENGTH),
            QrLinkDomainObjectAbstract::DESTINATION_TYPE => QrLinkDestinationType::URL->value,
            QrLinkDomainObjectAbstract::DESTINATION_URL => $url,
            QrLinkDomainObjectAbstract::STATUS => QrLinkStatus::ACTIVE->value,
            QrLinkDomainObjectAbstract::IS_AUTOMATIC_SMS_LINK => true,
        ]);
    }
}
