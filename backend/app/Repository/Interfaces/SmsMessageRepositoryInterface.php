<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsMessageDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SmsMessageDomainObject>
 */
interface SmsMessageRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, SmsMessageDomainObject>
     */
    public function findLatestInbound(int $conversationId, int $limit): Collection;

    /**
     * The most recent messages of a conversation, oldest first.
     *
     * @return Collection<int, SmsMessageDomainObject>
     */
    public function findLatestForConversation(int $conversationId, int $limit): Collection;

    /**
     * The organizer, among the given ones, who sent the most recent SMS to this number since the given date,
     * from the given line when one is given. Messages of team conversations and supervision copies do not count.
     *
     * @param  array<int, int>  $organizerIds
     */
    public function findLastOutboundOrganizerId(array $organizerIds, string $phone, string $since, ?int $lineSettingsId = null): ?int;

    /**
     * Whether one of the given organizers sent (or tried to send) an SMS to this number after the given message,
     * or still has one waiting to leave since the given date.
     *
     * @param  array<int, int>  $organizerIds
     */
    public function hasOutboundToPhoneAfter(array $organizerIds, string $phone, int $afterMessageId, string $pendingSince): bool;

    /**
     * Inbound SMS of the given organizers received between the two dates that no outbound SMS of the conversation
     * followed (failed or expired ones do not count). Closed, team and opted out conversations are left aside.
     * The most recent ones within the limit, returned oldest first.
     *
     * @param  array<int, int>  $organizerIds
     * @return Collection<int, SmsMessageDomainObject>
     */
    public function findUnansweredInbound(array $organizerIds, string $since, string $receivedBefore, int $limit): Collection;

    /**
     * Inbound SMS and replies (by the team or the assistant) of the given organizers created since the date.
     * The most recent ones within the limit, returned oldest first.
     *
     * @param  array<int, int>  $organizerIds
     * @return Collection<int, SmsMessageDomainObject>
     */
    public function findRepliesTrafficSince(array $organizerIds, string $since, int $limit): Collection;

    /**
     * Outbound SMS of the given organizers created since the date that failed, expired, or are still pending
     * since before the given date. The most recent ones within the limit, returned oldest first.
     *
     * @param  array<int, int>  $organizerIds
     * @return Collection<int, SmsMessageDomainObject>
     */
    public function findUndeliveredOutbound(array $organizerIds, string $since, string $pendingBefore, int $limit): Collection;

    /**
     * The organizer this number wrote to last, among the given organizers.
     *
     * @param  array<int, int>  $organizerIds
     */
    public function findLastInboundOrganizerId(array $organizerIds, string $phone): ?int;

    /**
     * The latest SMS of the organizer to this number that waits for an answer (failed or expired ones left aside).
     */
    public function findLatestAwaitingReply(int $organizerId, string $phone): ?SmsMessageDomainObject;

    /**
     * Whether this number wrote to the organizer after the given message.
     */
    public function hasInboundFromPhoneAfter(int $organizerId, string $phone, int $afterMessageId): bool;
}
