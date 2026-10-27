<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Media;

use HiEvents\Services\Domain\Social\Media\DTO\SocialMediaMetadataDTO;

/**
 * Reads duration, display size and codecs from the "moov" box of an MP4 or MOV file, without decoding it.
 * Used when ffprobe is not installed.
 */
class Mp4MetadataReader
{
    private const MAX_MOOV_BYTES = 32 * 1024 * 1024;

    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    public function read(string $path): ?SocialMediaMetadataDTO
    {
        $moov = $this->readMoov($path);

        if ($moov === null) {
            return null;
        }

        $durationMs = null;
        $width = null;
        $height = null;
        $videoCodec = null;
        $audioCodec = null;

        foreach ($this->boxes($moov) as [$type, $payload]) {
            if ($type === 'mvhd') {
                $durationMs = $this->movieDuration($payload);
            }

            if ($type !== 'trak') {
                continue;
            }

            $track = $this->readTrack($payload);

            if ($track['handler'] === 'vide' && $videoCodec === null) {
                $videoCodec = $track['codec'];
                [$width, $height] = [$track['width'], $track['height']];
            }

            if ($track['handler'] === 'soun' && $audioCodec === null) {
                $audioCodec = $track['codec'];
            }
        }

        return new SocialMediaMetadataDTO(
            width: $width,
            height: $height,
            durationMs: $durationMs,
            videoCodec: $videoCodec,
            audioCodec: $audioCodec,
        );
    }

    private function readMoov(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $fileSize = (int) filesize($path);
            $offset = 0;

            while ($offset + 8 <= $fileSize) {
                fseek($handle, $offset);
                $header = fread($handle, 16);

                if ($header === false || strlen($header) < 8) {
                    return null;
                }

                $size = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4);
                $headerSize = 8;

                if ($size === 1) {
                    $size = (int) unpack('J', substr($header, 8, 8))[1];
                    $headerSize = 16;
                } elseif ($size === 0) {
                    $size = $fileSize - $offset;
                }

                if ($size < $headerSize) {
                    return null;
                }

                if ($type === 'moov') {
                    $length = $size - $headerSize;

                    if ($length > self::MAX_MOOV_BYTES) {
                        return null;
                    }

                    fseek($handle, $offset + $headerSize);
                    $moov = $length > 0 ? fread($handle, $length) : '';

                    return $moov === false ? null : $moov;
                }

                $offset += $size;
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function boxes(string $data): array
    {
        $boxes = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $headerSize = 8;

            if ($size === 1 && $offset + 16 <= $length) {
                $size = (int) unpack('J', substr($data, $offset + 8, 8))[1];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $length - $offset;
            }

            if ($size < $headerSize || $offset + $size > $length) {
                break;
            }

            $boxes[] = [$type, substr($data, $offset + $headerSize, $size - $headerSize)];
            $offset += $size;
        }

        return $boxes;
    }

    /**
     * @return array{handler: ?string, codec: ?string, width: ?int, height: ?int}
     */
    private function readTrack(string $trak): array
    {
        $track = ['handler' => null, 'codec' => null, 'width' => null, 'height' => null];
        $this->walkTrack($trak, $track);

        return $track;
    }

    private function walkTrack(string $data, array &$track): void
    {
        foreach ($this->boxes($data) as [$type, $payload]) {
            match ($type) {
                'tkhd' => $this->readTrackHeader($payload, $track),
                'hdlr' => $track['handler'] ??= strlen($payload) >= 12 ? substr($payload, 8, 4) : null,
                'stsd' => $track['codec'] = strlen($payload) >= 16 ? rtrim(substr($payload, 12, 4)) : null,
                default => in_array($type, self::CONTAINERS, true) ? $this->walkTrack($payload, $track) : null,
            };
        }
    }

    private function readTrackHeader(string $payload, array &$track): void
    {
        if (strlen($payload) < 84) {
            return;
        }

        $version = ord($payload[0]);
        $matrixOffset = $version === 1 ? 52 : 40;
        $sizeOffset = $matrixOffset + 36;

        if (strlen($payload) < $sizeOffset + 8) {
            return;
        }

        $width = (int) round(unpack('N', substr($payload, $sizeOffset, 4))[1] / 65536);
        $height = (int) round(unpack('N', substr($payload, $sizeOffset + 4, 4))[1] / 65536);
        $b = unpack('N', substr($payload, $matrixOffset + 4, 4))[1];

        $rotated = $b === 0x00010000 || $b === 0xFFFF0000;

        $track['width'] = $rotated ? $height : $width;
        $track['height'] = $rotated ? $width : $height;
    }

    private function movieDuration(string $payload): ?int
    {
        $version = ord($payload[0] ?? "\0");

        if ($version === 1 && strlen($payload) >= 32) {
            $timescale = unpack('N', substr($payload, 20, 4))[1];
            $duration = (int) unpack('J', substr($payload, 24, 8))[1];
        } elseif (strlen($payload) >= 20) {
            $timescale = unpack('N', substr($payload, 12, 4))[1];
            $duration = unpack('N', substr($payload, 16, 4))[1];
        } else {
            return null;
        }

        return $timescale > 0 ? (int) round($duration * 1000 / $timescale) : null;
    }
}
