<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Exceptions\PosterMediaException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\AppliedEventPosterDTO;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Domain\Event\Presentation\EventPresentationTemplateService;
use HiEvents\Services\Domain\Event\Presentation\PosterMediaService;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Gives a new event the default PICHA page. With a poster: the poster's colour, the full poster as flyer
 * and a short banner as cover.
 */
class ApplyEventPosterHandler
{
    public function __construct(
        private readonly EventPresentationTemplateService $templateService,
        private readonly PosterMediaService $posterMediaService,
        private readonly CreateImageHandler $createImageHandler,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws PosterMediaException
     * @throws CouldNotUploadImageException
     */
    public function handle(int $eventId, int $organizerId, int $accountId, int $userId, ?string $posterPath): AppliedEventPosterDTO
    {
        if ($posterPath === null) {
            $this->templateService->apply($eventId, $organizerId);

            return new AppliedEventPosterDTO(accent: EventPresentationTemplateService::DEFAULT_ACCENT);
        }

        $workDir = storage_path('app/tmp/poster-'.Str::random(12));
        File::ensureDirectoryExists($workDir);

        try {
            $media = $this->posterMediaService->build($posterPath, $workDir);
            $this->templateService->apply($eventId, $organizerId, $media->accent);

            $this->uploadImage($posterPath, ImageType::EVENT_FLYER, $eventId, $accountId, $userId, $workDir);
            $this->uploadImage($media->banner_path, ImageType::EVENT_COVER, $eventId, $accountId, $userId, $workDir);

            return new AppliedEventPosterDTO(accent: $media->accent);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @throws CouldNotUploadImageException
     */
    private function uploadImage(string $path, ImageType $type, int $eventId, int $accountId, int $userId, string $workDir): void
    {
        $copy = $workDir.'/'.Str::lower($type->name).'.jpg';
        File::copy($path, $copy);

        $this->createImageHandler->handle(new CreateImageDTO(
            userId: $userId,
            accountId: $accountId,
            image: new UploadedFile($copy, basename($copy), 'image/jpeg', null, true),
            imageType: $type,
            entityId: $eventId,
        ));
    }
}
