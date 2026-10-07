<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Image;

use Illuminate\Http\UploadedFile;
use Imagick;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes phone photos uploadable: HEIC/HEIF/AVIF become JPEG, and photos larger than the
 * accepted maximum are scaled down, before the request is validated.
 */
class UploadedImageNormalizer
{
    public const MAX_DIMENSION = 4000;

    private const CONVERTED_MIME_TYPES = [
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
        'image/avif',
    ];

    private const CONVERTED_EXTENSIONS = ['heic', 'heif', 'avif'];

    private const RESIZABLE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const JPEG_QUALITY = 90;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function normalize(UploadedFile $file): UploadedFile
    {
        if (! extension_loaded('imagick') || ! $file->isValid()) {
            return $file;
        }

        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower($file->getClientOriginalExtension());
        $mustConvert = in_array($mimeType, self::CONVERTED_MIME_TYPES, true)
            || in_array($extension, self::CONVERTED_EXTENSIONS, true);

        if (! $mustConvert && ! in_array($mimeType, self::RESIZABLE_MIME_TYPES, true)) {
            return $file;
        }

        try {
            $image = new Imagick($file->getRealPath());
            $image = $image->coalesceImages()->getImage();

            $tooLarge = $image->getImageWidth() > self::MAX_DIMENSION || $image->getImageHeight() > self::MAX_DIMENSION;
            if (! $mustConvert && ! $tooLarge) {
                $image->clear();

                return $file;
            }

            $image->autoOrient();
            if ($tooLarge) {
                $image->resizeImage(self::MAX_DIMENSION, self::MAX_DIMENSION, Imagick::FILTER_LANCZOS, 1, true);
            }

            [$format, $outputMime, $outputExtension] = $mustConvert
                ? ['jpeg', 'image/jpeg', 'jpg']
                : [strtolower($image->getImageFormat()), $mimeType, $extension ?: 'jpg'];

            if ($format === 'jpeg') {
                $image->setImageBackgroundColor('white');
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $image->setImageCompressionQuality(self::JPEG_QUALITY);
            }
            $image->setImageFormat($format);

            $path = tempnam(sys_get_temp_dir(), 'upload');
            file_put_contents($path, $image->getImagesBlob());
            $image->clear();
            register_shutdown_function(static fn () => @unlink($path));

            $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'image';

            return new UploadedFile($path, $name.'.'.$outputExtension, $outputMime, null, true);
        } catch (Throwable $exception) {
            $this->logger->warning('Uploaded image could not be normalized, keeping the original file', [
                'mime_type' => $mimeType,
                'exception' => $exception->getMessage(),
            ]);

            return $file;
        }
    }
}
