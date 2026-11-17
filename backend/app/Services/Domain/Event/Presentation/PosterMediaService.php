<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\Presentation;

use HiEvents\Exceptions\PosterMediaException;
use HiEvents\Services\Domain\Event\Presentation\DTO\PosterMediaDTO;
use Imagick;
use ImagickException;
use ImagickPixel;

/**
 * Builds the event header from the poster: accent colour and a short banner around the artists' faces,
 * which sit in the upper part of a portrait poster. The header video is cut from the artist's official clip, not from the poster.
 */
class PosterMediaService
{
    private const BANNER_WIDTH = 1600;

    private const BANNER_HEIGHT = 560;

    private const FACES_CENTER = 0.22;

    /**
     * @throws PosterMediaException
     */
    public function build(string $posterPath, string $workDir): PosterMediaDTO
    {
        $poster = $this->open($posterPath);
        $accent = $this->accentColor($poster);
        $banner = $workDir.'/banniere.jpg';

        try {
            $this->bandAroundFaces($poster, self::BANNER_WIDTH, self::BANNER_HEIGHT, 1.0)->writeImage($banner);
        } catch (ImagickException $e) {
            throw new PosterMediaException(__('The banner could not be created: :error', ['error' => $e->getMessage()]));
        }

        return new PosterMediaDTO(
            accent: $accent,
            banner_path: $banner,
        );
    }

    /**
     * Most common vivid hue of the poster, as #rrggbbff.
     *
     * @throws PosterMediaException
     */
    public function accentColor(Imagick $poster): string
    {
        try {
            $sample = clone $poster;
            $sample->thumbnailImage(64, 64, true);
            $iterator = $sample->getPixelIterator();
        } catch (ImagickException $e) {
            throw new PosterMediaException($e->getMessage());
        }

        $bins = [];

        foreach ($iterator as $row) {
            /** @var ImagickPixel $pixel */
            foreach ($row as $pixel) {
                ['r' => $r, 'g' => $g, 'b' => $b] = $pixel->getColor();
                [$hue, $saturation, $value] = $this->hsv($r, $g, $b);

                if ($saturation < 0.55 || $value < 0.55) {
                    continue;
                }

                $bin = (int) floor($hue / 30) % 12;
                $bins[$bin][] = [$r, $g, $b];
            }
        }

        if ($bins === []) {
            return EventPresentationTemplateService::DEFAULT_ACCENT;
        }

        uasort($bins, fn (array $a, array $b) => count($b) <=> count($a));
        $pixels = reset($bins);
        $channel = fn (int $i) => (int) round(array_sum(array_column($pixels, $i)) / count($pixels));

        return sprintf('#%02x%02x%02xff', $channel(0), $channel(1), $channel(2));
    }

    /**
     * @throws PosterMediaException
     */
    private function open(string $path): Imagick
    {
        try {
            $image = new Imagick($path);
            $image->setImageFormat('jpeg');

            return $image;
        } catch (ImagickException) {
            throw new PosterMediaException(__('The poster is not a readable image.'));
        }
    }

    /**
     * @throws ImagickException
     */
    private function bandAroundFaces(Imagick $poster, int $width, int $height, float $zoom): Imagick
    {
        $band = clone $poster;
        $scaledWidth = (int) round($width * $zoom);
        $band->resizeImage($scaledWidth, 0, Imagick::FILTER_LANCZOS, 1);
        $top = (int) round($band->getImageHeight() * self::FACES_CENTER - $height / 2);
        $top = max(0, min($top, $band->getImageHeight() - $height));
        $band->cropImage($width, $height, (int) (($scaledWidth - $width) / 2), $top);
        $band->setImagePage(0, 0, 0, 0);
        $band->setImageCompressionQuality(85);

        return $band;
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue in degrees, saturation and value in 0..1
     */
    private function hsv(float $r, float $g, float $b): array
    {
        [$r, $g, $b] = [$r / 255, $g / 255, $b / 255];
        $max = max($r, $g, $b);
        $delta = $max - min($r, $g, $b);

        $hue = match (true) {
            $delta == 0.0 => 0.0,
            $max === $r => 60 * fmod(($g - $b) / $delta, 6),
            $max === $g => 60 * (($b - $r) / $delta + 2),
            default => 60 * (($r - $g) / $delta + 4),
        };

        return [$hue < 0 ? $hue + 360 : $hue, $max == 0.0 ? 0.0 : $delta / $max, $max];
    }
}
