<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Email;

use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailThreadSearchDTO;
use HiEvents\Services\Domain\Email\Inbox\EmailReplyAddressService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetEmailThreadsHandler
{
    public function __construct(
        private readonly EmailThreadRepositoryInterface $threadRepository,
        private readonly EmailReplyAddressService $replyAddressService,
    ) {}

    public function handle(int $organizerId, EmailThreadSearchDTO $search): LengthAwarePaginator
    {
        return $this->threadRepository->searchForOrganizer($organizerId, $search);
    }

    public function countUnread(int $organizerId): int
    {
        return $this->threadRepository->countUnreadForOrganizer($organizerId);
    }

    public function isReplyTrackingEnabled(): bool
    {
        return $this->replyAddressService->isEnabled();
    }
}
