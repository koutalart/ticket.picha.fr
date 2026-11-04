<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Services\Application\Handlers\Social\DTO\RequestSocialPostChangesDTO;
use HiEvents\Services\Domain\Social\SocialPostService;

class RequestSocialPostChangesHandler
{
    public function __construct(
        private readonly SocialPostService $postService,
    ) {}

    /**
     * @throws SocialPostNotEditableException
     */
    public function handle(RequestSocialPostChangesDTO $dto): SocialPostDomainObject
    {
        return $this->postService->requestChanges($dto->organizerId, $dto->postId, $dto->userId, $dto->comment);
    }
}
