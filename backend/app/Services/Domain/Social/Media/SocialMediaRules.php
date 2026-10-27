<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Media;

use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Services\Domain\Social\Media\DTO\SocialMediaMetadataDTO;

/**
 * Limits of Instagram and Facebook that PICHA checks before a post is scheduled.
 */
class SocialMediaRules
{
    public const MIME_TYPES = [
        'image/jpeg' => SocialMediaType::IMAGE,
        'image/png' => SocialMediaType::IMAGE,
        'video/mp4' => SocialMediaType::VIDEO,
        'video/quicktime' => SocialMediaType::VIDEO,
    ];

    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
    ];

    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public const MAX_VIDEO_BYTES = 100 * 1024 * 1024;

    public const VERTICAL_RATIO = 9 / 16;

    public const RATIO_TOLERANCE = 0.02;

    public const REEL_MIN_SECONDS = 3;

    public const REEL_MAX_SECONDS = 90;

    public const STORY_MAX_SECONDS = 60;

    /**
     * @throws InvalidSocialMediaFileException
     */
    public function typeForMime(string $mimeType): SocialMediaType
    {
        $type = self::MIME_TYPES[strtolower($mimeType)] ?? null;

        if ($type === null) {
            throw new InvalidSocialMediaFileException(__('Only JPG and PNG images and MP4 and MOV videos can be imported.'));
        }

        return $type;
    }

    /**
     * @throws InvalidSocialMediaFileException
     */
    public function assertSize(SocialMediaType $type, int $bytes): void
    {
        $max = $type === SocialMediaType::VIDEO ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES;

        if ($bytes <= 0 || $bytes > $max) {
            throw new InvalidSocialMediaFileException($type === SocialMediaType::VIDEO
                ? __('A video can weigh at most :max MB.', ['max' => self::MAX_VIDEO_BYTES / 1024 / 1024])
                : __('An image can weigh at most :max MB.', ['max' => self::MAX_IMAGE_BYTES / 1024 / 1024]));
        }
    }

    /**
     * @throws InvalidSocialMediaFileException
     */
    public function assertFitsFormat(SocialPostFormat $format, SocialMediaType $type, SocialMediaMetadataDTO $metadata): void
    {
        if ($type !== SocialMediaType::VIDEO || ! in_array($format, [SocialPostFormat::REEL, SocialPostFormat::STORY], true)) {
            return;
        }

        $seconds = $metadata->durationMs !== null ? $metadata->durationMs / 1000 : null;

        if ($format === SocialPostFormat::REEL) {
            if ($metadata->width && $metadata->height && abs($metadata->width / $metadata->height - self::VERTICAL_RATIO) > self::RATIO_TOLERANCE) {
                throw new InvalidSocialMediaFileException(__('A reel must be a vertical 9:16 video (for example 1080x1920). This one is :width x :height.', [
                    'width' => $metadata->width,
                    'height' => $metadata->height,
                ]));
            }

            if ($seconds !== null && ($seconds < self::REEL_MIN_SECONDS || $seconds > self::REEL_MAX_SECONDS)) {
                throw new InvalidSocialMediaFileException(__('A reel must last between :min and :max seconds.', [
                    'min' => self::REEL_MIN_SECONDS,
                    'max' => self::REEL_MAX_SECONDS,
                ]));
            }
        }

        if ($format === SocialPostFormat::STORY && $seconds !== null && $seconds > self::STORY_MAX_SECONDS) {
            throw new InvalidSocialMediaFileException(__('A video story can last at most :max seconds.', ['max' => self::STORY_MAX_SECONDS]));
        }
    }
}
