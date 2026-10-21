<?php

namespace HiEvents\Services\Application\Handlers\Organizer\CoverVideo;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\CoverVideoUploadException;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\CoverVideo\DTO\UploadOrganizerCoverVideoDTO;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use Illuminate\Database\DatabaseManager;

class UploadOrganizerCoverVideoHandler
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly ImageStorageService $imageStorageService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws CoverVideoUploadException
     */
    public function handle(UploadOrganizerCoverVideoDTO $dto): ImageDomainObject
    {
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $dto->organizerId,
            'account_id' => $dto->accountId,
        ]);

        if ($organizer === null) {
            throw new CoverVideoUploadException(__('Organizer not found'));
        }

        $type = $dto->mobile ? ImageType::ORGANIZER_COVER_VIDEO_MOBILE : ImageType::ORGANIZER_COVER_VIDEO;

        try {
            $stored = $this->imageStorageService->store($dto->video, $type->name);
        } catch (CouldNotUploadImageException) {
            throw new CoverVideoUploadException(__('The video could not be saved. Please try again.'));
        }

        return $this->databaseManager->transaction(function () use ($dto, $stored, $type) {
            $this->imageRepository->deleteWhere([
                'entity_id' => $dto->organizerId,
                'entity_type' => OrganizerDomainObject::class,
                'type' => $type->name,
            ]);

            return $this->imageRepository->create([
                'account_id' => $dto->accountId,
                'entity_id' => $dto->organizerId,
                'entity_type' => OrganizerDomainObject::class,
                'type' => $type->name,
                'filename' => $stored->filename,
                'disk' => $stored->disk,
                'path' => $stored->path,
                'size' => $stored->size,
                'mime_type' => $stored->mime_type,
            ]);
        });
    }
}
