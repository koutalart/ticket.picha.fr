<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\Mail\DTO\EmailLogoDTO;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Header logo of the e-mails sent to an event's participants:
 * the event's e-mail logo, then the organizer's e-mail logo, then the organizer's logo.
 * Null means the platform logo is kept.
 */
class EmailLogoResolver
{
    public function __construct(
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolveForEvent(?int $eventId, ?int $organizerId, ?string $alt = null): ?EmailLogoDTO
    {
        try {
            $image = $this->findImage($eventId, EventDomainObject::class, [ImageType::EVENT_EMAIL_LOGO])
                ?? $this->findImage($organizerId, OrganizerDomainObject::class, [
                    ImageType::ORGANIZER_EMAIL_LOGO,
                    ImageType::ORGANIZER_LOGO,
                ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Unable to load the e-mail logo, using the platform logo', [
                'event_id' => $eventId,
                'organizer_id' => $organizerId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($image === null) {
            return null;
        }

        $alt = $alt !== null ? trim($alt) : null;

        return new EmailLogoDTO(
            url: Url::getCdnUrl($image->getPath()),
            alt: $alt === '' ? null : $alt,
        );
    }

    /**
     * @param  ImageType[]  $typesByPriority
     */
    private function findImage(?int $entityId, string $entityType, array $typesByPriority): ?ImageDomainObject
    {
        if ($entityId === null) {
            return null;
        }

        /** @var Collection<int, ImageDomainObject> $images */
        $images = $this->imageRepository->findWhere([
            ImageDomainObjectAbstract::ENTITY_ID => $entityId,
            ImageDomainObjectAbstract::ENTITY_TYPE => $entityType,
        ]);

        foreach ($typesByPriority as $type) {
            $image = $images->first(
                static fn (ImageDomainObject $image) => $image->getType() === $type->name && ! empty($image->getPath())
            );

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }
}
