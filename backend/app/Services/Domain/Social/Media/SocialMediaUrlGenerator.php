<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Media;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\Helper\Url;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Temporary signed public URLs of the imported media, given only to Meta, which downloads them to publish.
 * The URL ends with the file extension: Meta and the validator detect the type from it.
 */
class SocialMediaUrlGenerator
{
    public const ROUTE_NAME = 'social.media.public';

    public const PUBLISHING_VALIDITY_HOURS = 48;

    public const PREVIEW_PATH = 'organizers/%d/social/media/%d/content';

    public function __construct(
        private readonly UrlGenerator $urlGenerator,
    ) {}

    public function publicUrl(SocialMediaFileDomainObject $file, CarbonInterface $expiresAt): string
    {
        $relativeUrl = $this->urlGenerator->temporarySignedRoute(
            name: self::ROUTE_NAME,
            expiration: $expiresAt,
            parameters: [
                'media_id' => $file->getId(),
                'file_name' => self::publicFileName($file),
            ],
            absolute: false,
        );

        return Url::getApiUrl($relativeUrl);
    }

    public function previewUrl(int $organizerId, int $fileId): string
    {
        return Url::getApiUrl(sprintf(self::PREVIEW_PATH, $organizerId, $fileId));
    }

    public static function publicFileName(SocialMediaFileDomainObject $file): string
    {
        return 'media-'.$file->getId().'.'.(SocialMediaRules::EXTENSIONS[$file->getMimeType()] ?? 'bin');
    }
}
