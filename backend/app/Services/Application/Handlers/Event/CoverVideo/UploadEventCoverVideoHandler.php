<?php

namespace HiEvents\Services\Application\Handlers\Event\CoverVideo;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Exceptions\CoverVideoUploadException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use Illuminate\Database\DatabaseManager;

class UploadEventCoverVideoHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly ImageStorageService $imageStorageService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws CoverVideoUploadException
     */
    public function handle(UploadEventCoverVideoDTO $dto): ImageDomainObject
    {
        $event = $this->eventRepository->findFirstWhere([
            'id' => $dto->eventId,
            'account_id' => $dto->accountId,
        ]);

        if ($event === null) {
            throw new CoverVideoUploadException(__('Event not found'));
        }

        try {
            $stored = $this->imageStorageService->store($dto->video, ImageType::EVENT_COVER_VIDEO->name);
        } catch (CouldNotUploadImageException) {
            throw new CoverVideoUploadException(__('The video could not be saved. Please try again.'));
        }

        return $this->databaseManager->transaction(function () use ($dto, $stored) {
            $this->imageRepository->deleteWhere([
                'entity_id' => $dto->eventId,
                'entity_type' => EventDomainObject::class,
                'type' => ImageType::EVENT_COVER_VIDEO->name,
            ]);

            return $this->imageRepository->create([
                'account_id' => $dto->accountId,
                'entity_id' => $dto->eventId,
                'entity_type' => EventDomainObject::class,
                'type' => ImageType::EVENT_COVER_VIDEO->name,
                'filename' => $stored->filename,
                'disk' => $stored->disk,
                'path' => $stored->path,
                'size' => $stored->size,
                'mime_type' => $stored->mime_type,
            ]);
        });
    }
}
