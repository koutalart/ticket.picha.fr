<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;

class CompleteSocialMediaUploadHandler
{
    public function __construct(
        private readonly SocialMediaFileService $mediaFileService,
    ) {}

    /**
     * @throws InvalidSocialMediaFileException
     * @throws SocialMediaFileNotFoundException
     */
    public function handle(int $organizerId, int $fileId, ?SocialPostFormat $format): SocialMediaFileDomainObject
    {
        return $this->mediaFileService->complete($organizerId, $fileId, $format);
    }
}
