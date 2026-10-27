<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use Illuminate\Http\UploadedFile;

class AppendSocialMediaChunkHandler
{
    public function __construct(
        private readonly SocialMediaFileService $mediaFileService,
    ) {}

    /**
     * @throws InvalidSocialMediaFileException
     * @throws SocialMediaFileNotFoundException
     */
    public function handle(int $organizerId, int $fileId, int $index, UploadedFile $chunk): SocialMediaFileDomainObject
    {
        return $this->mediaFileService->appendChunk($organizerId, $fileId, $index, $chunk);
    }
}
