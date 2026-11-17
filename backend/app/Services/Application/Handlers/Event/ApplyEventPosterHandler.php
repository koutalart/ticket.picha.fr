<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Exceptions\CoverVideoUploadException;
use HiEvents\Exceptions\PosterMediaException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
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
 * Gives a new event the default PICHA page. With a poster: the poster's colour, the full poster as flyer,
 * a short banner as cover and a looping header video built from the artists' faces.
 */
class ApplyEventPosterHandler
{
    public function __construct(
        private readonly EventPresentationTemplateService $templateService,
        private readonly PosterMediaService $posterMediaService,
        private readonly CreateImageHandler $createImageHandler,
        private readonly UploadEventCoverVideoHandler $uploadEventCoverVideoHandler,
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

            return new AppliedEventPosterDTO(accent: EventPresentationTemplateService::DEFAULT_ACCENT, video: false);
        }

        $workDir = storage_path('app/tmp/poster-'.Str::random(12));
        File::ensureDirectoryExists($workDir);

        try {
            $media = $this->posterMediaService->build($posterPath, $workDir);
            $this->templateService->apply($eventId, $organizerId, $media->accent);

            $this->uploadImage($posterPath, ImageType::EVENT_FLYER, $eventId, $accountId, $userId, $workDir);
            $this->uploadImage($media->banner_path, ImageType::EVENT_COVER, $eventId, $accountId, $userId, $workDir);

            $video = $this->uploadVideo($media->video_path, $eventId, $accountId, false)
                && $this->uploadVideo($media->mobile_video_path, $eventId, $accountId, true);

            return new AppliedEventPosterDTO(accent: $media->accent, video: $video);
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

    private function uploadVideo(?string $path, int $eventId, int $accountId, bool $mobile): bool
    {
        if ($path === null) {
            return false;
        }

        try {
            $this->uploadEventCoverVideoHandler->handle(new UploadEventCoverVideoDTO(
                eventId: $eventId,
                accountId: $accountId,
                video: new UploadedFile($path, basename($path), 'video/mp4', null, true),
                mobile: $mobile,
            ));
        } catch (CoverVideoUploadException) {
            return false;
        }

        return true;
    }
}
