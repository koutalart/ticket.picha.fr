<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicEventPagesDTO;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use HiEvents\Services\Domain\Event\EventPagesService;

class GetPublicEventPagesHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly EventPagesService $eventPagesService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(GetPublicEventPagesDTO $dto): EventPagesDTO
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findFirstWhere(['id' => $dto->eventId]);

        if ($event === null || ! $this->canView($event, $dto)) {
            throw new ResourceNotFoundException(__('Event not found'));
        }

        $photos = $this->imageRepository->findWhere([
            'entity_id' => $event->getId(),
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_ARTIST_PHOTO->name,
        ]);

        return $this->eventPagesService->present($event->getEventSettings()?->getEventPages(), $photos);
    }

    private function canView(EventDomainObject $event, GetPublicEventPagesDTO $dto): bool
    {
        if ($event->getStatus() === EventStatus::LIVE->name || $dto->isSuperAdmin) {
            return true;
        }

        return $dto->authenticatedAccountId !== null && $dto->authenticatedAccountId === $event->getAccountId();
    }
}
