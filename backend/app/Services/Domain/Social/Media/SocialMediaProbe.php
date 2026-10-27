<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Media;

use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\Services\Domain\Social\Media\DTO\SocialMediaMetadataDTO;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Size, duration and codecs of an uploaded media. Uses ffprobe when the server has it, otherwise reads the MP4/MOV boxes.
 */
class SocialMediaProbe
{
    private const FFPROBE_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Mp4MetadataReader $mp4Reader,
    ) {}

    public function probe(string $path, SocialMediaType $type): SocialMediaMetadataDTO
    {
        if ($type === SocialMediaType::IMAGE) {
            $size = @getimagesize($path);

            return new SocialMediaMetadataDTO(
                width: $size ? (int) $size[0] : null,
                height: $size ? (int) $size[1] : null,
            );
        }

        return $this->probeWithFfprobe($path) ?? $this->mp4Reader->read($path) ?? new SocialMediaMetadataDTO;
    }

    private function probeWithFfprobe(string $path): ?SocialMediaMetadataDTO
    {
        $binary = (new ExecutableFinder)->find('ffprobe');

        if ($binary === null) {
            return null;
        }

        try {
            $process = new Process([$binary, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path]);
            $process->setTimeout(self::FFPROBE_TIMEOUT_SECONDS);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $data = json_decode($process->getOutput(), true);
        } catch (Throwable) {
            return null;
        }

        $streams = collect($data['streams'] ?? []);
        $video = $streams->firstWhere('codec_type', 'video');
        $audio = $streams->firstWhere('codec_type', 'audio');
        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;

        if ($this->isQuarterTurn($video)) {
            [$width, $height] = [$height, $width];
        }

        $duration = $data['format']['duration'] ?? ($video['duration'] ?? null);

        return new SocialMediaMetadataDTO(
            width: $width,
            height: $height,
            durationMs: $duration !== null ? (int) round((float) $duration * 1000) : null,
            videoCodec: $this->fourcc($video),
            audioCodec: $this->fourcc($audio),
        );
    }

    private function isQuarterTurn(?array $stream): bool
    {
        $rotation = $stream['tags']['rotate'] ?? null;

        foreach ($stream['side_data_list'] ?? [] as $sideData) {
            $rotation ??= $sideData['rotation'] ?? null;
        }

        return $rotation !== null && abs((int) $rotation) % 180 === 90;
    }

    private function fourcc(?array $stream): ?string
    {
        if ($stream === null) {
            return null;
        }

        $tag = (string) ($stream['codec_tag_string'] ?? '');

        return $tag !== '' && ! str_starts_with($tag, '[') ? $tag : ($stream['codec_name'] ?? null);
    }
}
