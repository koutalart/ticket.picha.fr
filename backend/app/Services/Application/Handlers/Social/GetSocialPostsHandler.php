<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class GetSocialPostsHandler
{
    public function __construct(
        private readonly SocialPostRepositoryInterface $postRepository,
    ) {}

    public function handle(int $organizerId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->postRepository->paginateForOrganizer($organizerId, $status, $perPage);
    }
}
