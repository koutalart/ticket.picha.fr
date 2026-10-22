<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventPagesDTO;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use HiEvents\Services\Domain\Event\EventPagesService;
use Illuminate\Database\DatabaseManager;
use Throwable;

class UpdateEventPagesHandler
{
    public function __construct(
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly EventPagesService $eventPagesService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceNotFoundException|Throwable
     */
    public function handle(UpdateEventPagesDTO $dto): EventPagesDTO
    {
        $settings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $dto->eventId]);

        if ($settings === null) {
            throw new ResourceNotFoundException(__('Event settings not found'));
        }

        $photoConditions = [
            'entity_id' => $dto->eventId,
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_ARTIST_PHOTO->name,
        ];

        $photos = $this->imageRepository->findWhere($photoConditions);
        $photoIds = $photos->map(fn (ImageDomainObject $image) => $image->getId())->all();

        $pages = $this->eventPagesService->normalize($dto->pages, $photoIds);

        $usedPhotoIds = collect($pages['artists'] ?? [])->pluck('image_id')->filter()->values()->all();
        $unusedPhotoIds = array_values(array_diff($photoIds, $usedPhotoIds));

        $this->databaseManager->transaction(function () use ($settings, $pages, $photoConditions, $unusedPhotoIds) {
            $this->eventSettingsRepository->updateFromArray($settings->getId(), [
                'event_pages' => $pages,
            ]);

            if ($unusedPhotoIds !== []) {
                $this->imageRepository->deleteWhere([
                    ...$photoConditions,
                    ['id', 'in', $unusedPhotoIds],
                ]);
            }
        });

        return $this->eventPagesService->present(
            $pages,
            $photos->filter(fn (ImageDomainObject $image) => in_array($image->getId(), $usedPhotoIds, true)),
        );
    }
}
