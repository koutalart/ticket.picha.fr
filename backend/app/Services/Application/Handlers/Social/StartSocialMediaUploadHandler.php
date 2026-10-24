<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;

class StartSocialMediaUploadHandler
{
    public function __construct(
        private readonly SocialMediaFileService $mediaFileService,
    ) {}

    /**
     * @throws InvalidSocialMediaFileException
     */
    public function handle(int $accountId, int $organizerId, int $userId, string $fileName, string $mimeType, int $sizeBytes): SocialMediaFileDomainObject
    {
        return $this->mediaFileService->start($accountId, $organizerId, $userId, $fileName, $mimeType, $sizeBytes);
    }
}
