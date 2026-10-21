<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\HomepageBackgroundType;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\DeleteEventImageDTO;
use Illuminate\Database\DatabaseManager;
use Throwable;

class DeleteEventImageHandler
{
    public function __construct(
        private readonly ImageRepositoryInterface         $imageRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly DatabaseManager                  $databaseManager,
    )
    {
    }

    /**
     * @throws Throwable
     */
    public function handle(DeleteEventImageDTO $deleteEventImageDTO): void
    {
        $this->databaseManager->transaction(function () use ($deleteEventImageDTO) {
            $where = [
                'entity_id' => $deleteEventImageDTO->eventId,
                'entity_type' => EventDomainObject::class,
                'id' => $deleteEventImageDTO->imageId,
            ];

            /** @var ImageDomainObject|null $image */
            $image = $this->imageRepository->findFirstWhere($where);

            if ($image === null) {
                return;
            }

            if ($image->getType() === ImageType::EVENT_COVER->name) {
                $this->resetMirroredCoverBackground($deleteEventImageDTO->eventId);
            }

            $this->imageRepository->deleteWhere($where);
        });
    }

    private function resetMirroredCoverBackground(int $eventId): void
    {
        $eventSettings = $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $eventId,
        ]);

        if ($eventSettings?->getHomepageBackgroundType() !== HomepageBackgroundType::MIRROR_COVER_IMAGE->name) {
            return;
        }

        $this->eventSettingsRepository->updateWhere(attributes: [
            'homepage_background_type' => HomepageBackgroundType::COLOR->name,
        ], where: [
            'event_id' => $eventId,
        ]);
    }
}
