<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Email;

use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailRecipientDTO;
use Illuminate\Support\Collection;

class SearchEmailRecipientsHandler
{
    private const LIMIT = 20;

    public function __construct(
        private readonly EmailThreadRepositoryInterface $threadRepository,
    ) {}

    /**
     * @return Collection<int, EmailRecipientDTO>
     */
    public function handle(int $organizerId, string $query): Collection
    {
        return $this->threadRepository->searchRecipients($organizerId, $query, self::LIMIT);
    }
}
