<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use HiEvents\Services\Domain\Event\EventPagesService;

class GetEventPagesHandler
{
    public function __construct(
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly EventPagesService $eventPagesService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId): EventPagesDTO
    {
        $settings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $eventId]);

        if ($settings === null) {
            throw new ResourceNotFoundException(__('Event settings not found'));
        }

        $photos = $this->imageRepository->findWhere([
            'entity_id' => $eventId,
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_ARTIST_PHOTO->name,
        ]);

        return $this->eventPagesService->present($settings->getEventPages(), $photos);
    }
}
