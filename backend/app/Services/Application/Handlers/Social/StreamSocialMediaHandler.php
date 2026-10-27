<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\Generated\SocialMediaFileDomainObjectAbstract;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\DomainObjects\Status\SocialMediaFileStatus;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Repository\Interfaces\SocialMediaFileRepositoryInterface;
use HiEvents\Services\Domain\Social\Media\SocialMediaStreamer;
use Symfony\Component\HttpFoundation\Response;

class StreamSocialMediaHandler
{
    public function __construct(
        private readonly SocialMediaFileRepositoryInterface $fileRepository,
        private readonly SocialMediaStreamer $streamer,
    ) {}

    /**
     * @throws SocialMediaFileNotFoundException
     */
    public function handle(int $fileId, ?int $organizerId): Response
    {
        $where = [SocialMediaFileDomainObjectAbstract::ID => $fileId];

        if ($organizerId !== null) {
            $where[SocialMediaFileDomainObjectAbstract::ORGANIZER_ID] = $organizerId;
        }

        /** @var SocialMediaFileDomainObject|null $file */
        $file = $this->fileRepository->findFirstWhere($where);

        if ($file === null || $file->getStatus() !== SocialMediaFileStatus::READY->name) {
            throw new SocialMediaFileNotFoundException(__('Media not found.'));
        }

        return $this->streamer->response($file, private: $organizerId !== null);
    }
}
