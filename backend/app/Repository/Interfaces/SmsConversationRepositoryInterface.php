<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\Services\Domain\Sms\DTO\SmsConversationSearchDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SmsConversationDomainObject>
 */
interface SmsConversationRepositoryInterface extends RepositoryInterface
{
    public function searchForOrganizer(int $organizerId, SmsConversationSearchDTO $search): LengthAwarePaginator;

    public function countUnreadForOrganizer(int $organizerId): int;

    public function claimInboundNotification(int $conversationId, string $notifiedBefore, string $now): bool;

    public function findContactNameByPhone(int $organizerId, string $phone): ?string;

    /**
     * @return Collection<int, SmsCustomerOrderDTO>
     */
    public function findOrdersByPhone(int $organizerId, string $phone): Collection;
}
