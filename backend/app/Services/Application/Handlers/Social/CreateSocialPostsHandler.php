<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\SocialPostService;
use Illuminate\Support\Collection;
use Throwable;

class CreateSocialPostsHandler
{
    public function __construct(
        private readonly SocialPostService $postService,
    ) {}

    /**
     * @return Collection<int, SocialPostDomainObject>
     *
     * @throws InvalidSocialPostException
     * @throws Throwable
     */
    public function handle(CreateSocialPostsDTO $dto): Collection
    {
        return $this->postService->create($dto);
    }
}
