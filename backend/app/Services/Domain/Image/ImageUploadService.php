<?php

namespace HiEvents\Services\Domain\Image;

use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use HiEvents\Services\Infrastructure\Image\ImageMetadataService;
use HiEvents\Services\Infrastructure\Image\ImageOptimizationService;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use Illuminate\Http\UploadedFile;

class ImageUploadService
{
    public function __construct(
        private readonly ImageStorageService $imageStorageService,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly ImageMetadataService $imageMetadataService,
        private readonly ImageOptimizationService $imageOptimizationService,
    ) {}

    /**
     * @throws CouldNotUploadImageException
     */
    public function upload(
        UploadedFile $image,
        int $entityId,
        string $entityType,
        string $imageType,
        int $accountId,
    ): ImageDomainObject {
        $original = $image;
        $image = $this->optimize($original, $imageType);

        try {
            $storedImage = $this->imageStorageService->store($image, $imageType);
            $metadata = $this->imageMetadataService->extractMetadata($image);

            $data = [
                'account_id' => $accountId,
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'type' => $imageType,
                'filename' => $storedImage->filename,
                'disk' => $storedImage->disk,
                'path' => $storedImage->path,
                'size' => $storedImage->size,
                'mime_type' => $storedImage->mime_type,
            ];

            if ($metadata !== null) {
                $data['width'] = $metadata->width;
                $data['height'] = $metadata->height;
                $data['avg_colour'] = $metadata->avg_colour;
                $data['lqip_base64'] = $metadata->lqip_base64;
            }

            return $this->imageRepository->create($data);
        } finally {
            if ($image !== $original) {
                @unlink($image->getRealPath());
            }
        }
    }

    private function optimize(UploadedFile $image, string $imageType): UploadedFile
    {
        $optimized = $this->imageOptimizationService->optimize(
            contents: (string) file_get_contents($image->getRealPath()),
            mimeType: (string) $image->getMimeType(),
            imageType: $imageType,
        );

        if ($optimized === null) {
            return $image;
        }

        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $optimized->contents);
        $baseName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);

        return new UploadedFile($path, $baseName.'.'.$optimized->extension, $optimized->mimeType, null, true);
    }
}
