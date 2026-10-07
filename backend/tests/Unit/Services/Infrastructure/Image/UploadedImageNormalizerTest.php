<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Image;

use HiEvents\Http\Request\Image\CreateImageRequest;
use HiEvents\Services\Infrastructure\Image\UploadedImageNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Imagick;
use ImagickPixel;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class UploadedImageNormalizerTest extends TestCase
{
    private UploadedImageNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is not available');
        }

        $this->normalizer = new UploadedImageNormalizer(m::mock(LoggerInterface::class)->shouldIgnoreMissing());
    }

    private function upload(int $width, int $height, string $format, string $name): UploadedFile
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel('orange'));
        $image->setImageFormat($format);
        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, $image->getImagesBlob());

        return new UploadedFile($path, $name, null, null, true);
    }

    private function inspect(UploadedFile $file): array
    {
        $image = new Imagick($file->getRealPath());

        return [strtolower($image->getImageFormat()), $image->getImageWidth(), $image->getImageHeight()];
    }

    public function test_small_jpeg_is_left_untouched(): void
    {
        $file = $this->upload(1200, 800, 'jpeg', 'photo.jpg');

        self::assertSame($file, $this->normalizer->normalize($file));
    }

    public function test_oversized_jpeg_is_scaled_down_to_the_maximum(): void
    {
        $result = $this->normalizer->normalize($this->upload(6000, 3000, 'jpeg', 'appareil.jpg'));

        self::assertSame(['jpeg', 4000, 2000], $this->inspect($result));
        self::assertSame('appareil.jpg', $result->getClientOriginalName());
        self::assertSame('image/jpeg', $result->getMimeType());
    }

    public function test_iphone_heic_photo_is_converted_to_jpeg_and_accepted_by_the_upload_request(): void
    {
        if (Imagick::queryFormats('HEIC') === []) {
            $this->markTestSkipped('HEIC is not supported by this Imagick build');
        }

        $path = tempnam(sys_get_temp_dir(), 'test');
        copy(base_path('tests/Fixtures/Images/photo-iphone.heic'), $path);
        $file = new UploadedFile($path, 'IMG_0001.HEIC', null, null, true);

        $base = Request::create('/api/images', 'POST', ['image_type' => 'EVENT_COVER', 'entity_id' => 1], [], ['image' => $file]);
        $request = CreateImageRequest::createFrom($base);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $converted = $request->file('image');
        self::assertSame(['jpeg', 1200, 630], $this->inspect($converted));
        self::assertSame('IMG_0001.jpg', $converted->getClientOriginalName());
        self::assertSame('image/jpeg', $converted->getMimeType());
    }

    public function test_avif_photo_is_converted_to_jpeg(): void
    {
        if (Imagick::queryFormats('AVIF') === []) {
            $this->markTestSkipped('AVIF is not supported by this Imagick build');
        }

        try {
            $file = $this->upload(1600, 900, 'avif', 'couverture.avif');
        } catch (\ImagickException) {
            $this->markTestSkipped('This Imagick build cannot write AVIF test files');
        }

        self::assertSame(['jpeg', 1600, 900], $this->inspect($this->normalizer->normalize($file)));
    }

    public function test_non_image_file_is_left_untouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, '%PDF-1.4 not an image');
        $file = new UploadedFile($path, 'document.pdf', null, null, true);

        self::assertSame($file, $this->normalizer->normalize($file));
    }

    public function test_french_validation_messages_exist(): void
    {
        app()->setLocale('fr');
        app()->setFallbackLocale('fr');

        self::assertSame(
            'Le champ image doit être une image (JPG, PNG, WebP ou HEIC).',
            __('validation.image', ['attribute' => 'image']),
        );
        self::assertSame('Le champ e-mail est obligatoire.', __('validation.required', ['attribute' => __('validation.attributes.email')]));
    }

    public function test_upload_request_accepts_an_oversized_photo_after_normalization(): void
    {
        $file = $this->upload(6000, 3000, 'jpeg', 'couverture.jpg');
        $base = Request::create('/api/images', 'POST', ['image_type' => 'EVENT_COVER', 'entity_id' => 1], [], ['image' => $file]);

        $request = CreateImageRequest::createFrom($base);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        self::assertSame(['jpeg', 4000, 2000], $this->inspect($request->file('image')));
    }
}
