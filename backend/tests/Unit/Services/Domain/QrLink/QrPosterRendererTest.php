<?php

namespace Tests\Unit\Services\Domain\QrLink;

use HiEvents\Services\Domain\QrLink\DTO\QrPosterLogoDTO;
use HiEvents\Services\Domain\QrLink\QrPosterRenderer;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class QrPosterRendererTest extends TestCase
{
    public function test_presets_render_at_their_canvas_size(): void
    {
        $renderer = new QrPosterRenderer;

        foreach (QrPosterRenderer::PRESETS as $template => $preset) {
            $image = new Imagick;
            $image->readImageBlob($renderer->render('https://ticket.picha.fr/q/abc1234', [
                'template' => $template,
                'foreground' => '#40296C',
                'caption' => 'Scannez-moi',
            ], 'Concert au marché couvert'));

            $this->assertSame('PNG', $image->getImageFormat(), $template);
            $this->assertSame($preset['width'], $image->getImageWidth(), $template);
            $this->assertSame($preset['height'], $image->getImageHeight(), $template);
        }
    }

    public function test_custom_background_keeps_its_ratio_and_draws_the_qr_where_asked(): void
    {
        $background = new Imagick;
        $background->newImage(1000, 2000, new ImagickPixel('#ffc800'));
        $background->setImageFormat('png');

        $image = new Imagick;
        $image->readImageBlob((new QrPosterRenderer)->render('https://ticket.picha.fr/q/abc1234', [
            'template' => 'custom',
            'qr_position' => 30,
            'qr_scale' => 50,
            'background' => '#ffffff',
        ], null, $background->getImageBlob()));

        $this->assertSame(1000, $image->getImageWidth());
        $this->assertSame(2000, $image->getImageHeight());

        $cardTop = (int) round(2000 * 0.3 - 250);
        $this->assertSame('#ffffff', $this->hex($image->getImagePixelColor(500, $cardTop + 10)));
        $this->assertSame('#ffc800', $this->hex($image->getImagePixelColor(500, 1700)));
    }

    public function test_large_custom_backgrounds_are_downscaled(): void
    {
        $background = new Imagick;
        $background->newImage(3000, 3000, new ImagickPixel('#000000'));
        $background->setImageFormat('png');

        $image = new Imagick;
        $image->readImageBlob((new QrPosterRenderer)->render('https://x.test/q/a', ['template' => 'custom'], null, $background->getImageBlob()));

        $this->assertSame(2160, $image->getImageWidth());
    }

    public function test_custom_poster_draws_logo_and_title_above_the_qr_code(): void
    {
        $background = new Imagick;
        $background->newImage(1000, 2000, new ImagickPixel('#ffc800'));
        $background->setImageFormat('png');

        $icon = file_get_contents(base_path('resources/qr-poster-icons/brand-tiktok.svg'));

        $image = new Imagick;
        $image->readImageBlob((new QrPosterRenderer)->render('https://ticket.picha.fr/q/abc1234', [
            'template' => 'custom',
            'qr_position' => 47,
            'qr_scale' => 55,
            'poster_title' => 'TikTok',
            'caption_color' => '#ffffff',
        ], null, $background->getImageBlob(), new QrPosterLogoDTO(blob: $icon, isIcon: true)));

        $cardTop = (int) round(2000 * 0.47 - 275);
        $whitePixels = 0;
        for ($y = 300; $y < $cardTop - 20; $y += 4) {
            for ($x = 300; $x < 700; $x += 4) {
                if ($this->hex($image->getImagePixelColor($x, $y)) === '#ffffff') {
                    $whitePixels++;
                }
            }
        }

        $this->assertGreaterThan(200, $whitePixels, 'Logo and title should be drawn in white above the QR card');
    }

    private function hex(ImagickPixel $pixel): string
    {
        $color = $pixel->getColor();

        return sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
    }
}
