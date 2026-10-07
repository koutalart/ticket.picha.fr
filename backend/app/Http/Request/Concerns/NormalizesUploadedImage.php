<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Concerns;

use HiEvents\Services\Infrastructure\Image\UploadedImageNormalizer;

trait NormalizesUploadedImage
{
    protected function prepareForValidation(): void
    {
        $file = $this->file('image');
        if ($file === null || is_array($file)) {
            return;
        }

        $this->files->set('image', app(UploadedImageNormalizer::class)->normalize($file));
        $this->convertedFiles = null;
    }

    /**
     * @return array<string, string>
     */
    protected function imageFormatMessages(): array
    {
        $message = __('Unsupported image format. Use a JPG, PNG, WebP or HEIC photo (8 MB maximum).');

        return [
            'image.image' => $message,
            'image.mimes' => $message,
            'image.max' => __('The image must not be larger than 8 MB.'),
            'image.uploaded' => __('The image could not be uploaded. Use a JPG, PNG, WebP or HEIC photo (8 MB maximum).'),
        ];
    }
}
