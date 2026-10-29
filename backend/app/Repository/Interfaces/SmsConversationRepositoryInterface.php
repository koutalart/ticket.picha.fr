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

    /**
     * Open conversations of the given organizers holding an assistant draft generated between the two dates
     * that no outbound SMS followed yet (failed or expired ones do not count), oldest draft first.
     *
     * @param  array<int, int>  $organizerIds
     * @return Collection<int, SmsConversationDomainObject>
     */
    public function findWaitingAgentDrafts(array $organizerIds, string $since, string $generatedBefore, int $limit): Collection;

    /**
     * The organizer whose team conversation with this number had the latest message, among the given organizers.
     *
     * @param  array<int, int>  $organizerIds
     */
    public function findLatestTeamConversationOrganizerId(array $organizerIds, string $phone): ?int;

    /**
     * Conversations whose number is a Mayotte or La Réunion number written with +33 (+33639…, +33692…, +33693…,
     * +33262…, +33263…, +33269…).
     *
     * @return Collection<int, SmsConversationDomainObject>
     */
    public function findWithFrenchOverseasPhones(): Collection;

    /**
     * Moves the messages, web chats and assistant calls of a conversation into another one of the same organizer,
     * keeps the latest activity and the unread count of both, then removes the emptied conversation.
     *
     * @return int the number of messages moved
     */
    public function mergeInto(int $sourceId, int $targetId): int;
}
