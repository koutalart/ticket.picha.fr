<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Image;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Infrastructure\Image\DTO\OptimizedImageDTO;
use Imagick;
use Psr\Log\LoggerInterface;
use Throwable;

class ImageOptimizationService
{
    private const QUALITY = 82;

    private const PHOTO_MAX_DIMENSION = 1600;

    private const SHARE_MAX_WIDTH = 1200;

    private const LOGO_MAX_DIMENSION = 800;

    private const SKIPPED_MIME_TYPES = ['image/gif', 'image/svg+xml'];

    private const LOGO_TYPES = [
        ImageType::ORGANIZER_LOGO,
        ImageType::TICKET_LOGO,
        ImageType::TICKET_SPONSOR_LOGO,
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function optimize(string $contents, string $mimeType, string $imageType): ?OptimizedImageDTO
    {
        if (! extension_loaded('imagick') || in_array($mimeType, self::SKIPPED_MIME_TYPES, true)) {
            return null;
        }

        $type = defined(ImageType::class.'::'.$imageType) ? ImageType::fromName($imageType) : ImageType::GENERIC;

        try {
            $image = new Imagick;
            $image->readImageBlob($contents);
            $image = $image->coalesceImages()->getImage();
            $image->autoOrient();
            $image->stripImage();

            [$format, $mime, $extension, $maxWidth, $maxHeight] = $this->targetFor($type, $mimeType);
            $this->downscale($image, $maxWidth, $maxHeight);

            if ($format === 'jpeg') {
                $background = new Imagick;
                $background->newImage($image->getImageWidth(), $image->getImageHeight(), 'white');
                $background->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
                $image->clear();
                $image = $background;
            }

            $image->setImageFormat($format);
            if ($format !== 'png') {
                $image->setImageCompressionQuality(self::QUALITY);
            }

            $optimized = $image->getImagesBlob();
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $image->clear();

            $changedFormat = $mime !== $mimeType;
            if (! $changedFormat && strlen($optimized) >= strlen($contents)) {
                return null;
            }

            return new OptimizedImageDTO(
                contents: $optimized,
                mimeType: $mime,
                extension: $extension,
                width: $width,
                height: $height,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Image optimization failed, keeping the original file', [
                'image_type' => $imageType,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int, 4: int}
     */
    private function targetFor(ImageType $type, string $mimeType): array
    {
        if ($type === ImageType::EVENT_SHARE_IMAGE) {
            return ['jpeg', 'image/jpeg', 'jpg', self::SHARE_MAX_WIDTH, self::SHARE_MAX_WIDTH];
        }

        if (in_array($type, self::LOGO_TYPES, true)) {
            return $mimeType === 'image/jpeg'
                ? ['jpeg', 'image/jpeg', 'jpg', self::LOGO_MAX_DIMENSION, self::LOGO_MAX_DIMENSION]
                : ['png', 'image/png', 'png', self::LOGO_MAX_DIMENSION, self::LOGO_MAX_DIMENSION];
        }

        return ['webp', 'image/webp', 'webp', self::PHOTO_MAX_DIMENSION, self::PHOTO_MAX_DIMENSION];
    }

    private function downscale(Imagick $image, int $maxWidth, int $maxHeight): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);

        if ($scale < 1.0) {
            $image->resizeImage(
                max(1, (int) round($width * $scale)),
                max(1, (int) round($height * $scale)),
                Imagick::FILTER_LANCZOS,
                1,
            );
        }
    }
}
