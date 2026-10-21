<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsConversationSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetSmsConversationsHandler
{
    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
    ) {}

    public function handle(int $organizerId, SmsConversationSearchDTO $search): LengthAwarePaginator
    {
        return $this->conversationRepository->searchForOrganizer($organizerId, $search);
    }

    public function countUnread(int $organizerId): int
    {
        return $this->conversationRepository->countUnreadForOrganizer($organizerId);
    }
}
