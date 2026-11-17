<?php

namespace Tests\Unit\Services\Domain\Event\Presentation;

use HiEvents\Exceptions\PosterMediaException;
use HiEvents\Services\Domain\Event\Presentation\EventPresentationTemplateService;
use HiEvents\Services\Domain\Event\Presentation\PosterMediaService;
use Illuminate\Support\Facades\File;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Tests\TestCase;

class PosterMediaServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/poster-test-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function poster(string $background, string $vivid): string
    {
        $image = new Imagick;
        $image->newImage(400, 700, new ImagickPixel($background));
        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel($vivid));
        $draw->rectangle(0, 300, 399, 699);
        $image->drawImage($draw);
        $image->setImageFormat('jpeg');
        $path = $this->dir.'/poster.jpg';
        $image->writeImage($path);

        return $path;
    }

    public function test_the_accent_is_the_most_common_vivid_colour_of_the_poster(): void
    {
        $accent = (new PosterMediaService)->accentColor(new Imagick($this->poster('#101010', '#1f8fff')));

        [$r, $g, $b] = sscanf($accent, '#%02x%02x%02x');

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{8}$/', $accent);
        $this->assertGreaterThan(200, $b);
        $this->assertLessThan(80, $r);
        $this->assertGreaterThan($r, $g);
    }

    public function test_a_poster_without_vivid_colour_keeps_the_default_accent(): void
    {
        $accent = (new PosterMediaService)->accentColor(new Imagick($this->poster('#202020', '#808080')));

        $this->assertSame(EventPresentationTemplateService::DEFAULT_ACCENT, $accent);
    }

    public function test_it_builds_a_short_banner_around_the_faces(): void
    {
        $media = (new PosterMediaService)->build($this->poster('#101010', '#ff7a00'), $this->dir);

        $banner = new Imagick($media->banner_path);
        $this->assertSame([1600, 560], [$banner->getImageWidth(), $banner->getImageHeight()]);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $path = $this->dir.'/affiche.jpg';
        file_put_contents($path, 'pas une image');

        $this->expectException(PosterMediaException::class);

        (new PosterMediaService)->build($path, $this->dir);
    }
}
