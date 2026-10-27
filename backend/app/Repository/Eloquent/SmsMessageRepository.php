<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Models\SmsMessage;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SmsMessageDomainObject>
 */
class SmsMessageRepository extends BaseRepository implements SmsMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsMessage::class;
    }

    public function getDomainObject(): string
    {
        return SmsMessageDomainObject::class;
    }

    public function findLatestInbound(int $conversationId, int $limit): Collection
    {
        $messages = SmsMessage::query()
            ->where(SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID, $conversationId)
            ->where(SmsMessageDomainObjectAbstract::DIRECTION, SmsMessageDirection::INBOUND->name)
            ->orderByDesc(SmsMessageDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($messages)->reverse()->values();
    }

    public function findLatestForConversation(int $conversationId, int $limit): Collection
    {
        $messages = SmsMessage::query()
            ->where(SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID, $conversationId)
            ->orderByDesc(SmsMessageDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($messages)->reverse()->values();
    }

    public function findLastOutboundOrganizerId(array $organizerIds, string $phone, string $since, ?int $lineSettingsId = null): ?int
    {
        if ($organizerIds === []) {
            return null;
        }

        $sentAt = 'COALESCE(sms_messages.provider_timestamp, sms_messages.created_at)';

        $organizerId = SmsMessage::query()
            ->when($lineSettingsId !== null, fn (EloquentBuilder $query) => $query->where('sms_messages.line_settings_id', $lineSettingsId))
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->whereIn('sms_messages.organizer_id', $organizerIds)
            ->whereColumn('sms_conversations.organizer_id', 'sms_messages.organizer_id')
            ->where('sms_conversations.phone', $phone)
            ->where('sms_conversations.is_team', false)
            ->where('sms_messages.is_supervision', false)
            ->where('sms_messages.direction', SmsMessageDirection::OUTBOUND->name)
            ->whereNull('sms_messages.channel')
            ->whereNotIn('sms_messages.status', [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name])
            ->whereRaw($sentAt.' >= ?', [$since])
            ->orderByRaw($sentAt.' DESC')
            ->orderByDesc('sms_messages.id')
            ->value('sms_messages.organizer_id');

        return $organizerId === null ? null : (int) $organizerId;
    }

    public function hasOutboundToPhoneAfter(array $organizerIds, string $phone, int $afterMessageId, string $pendingSince): bool
    {
        if ($organizerIds === []) {
            return false;
        }

        return SmsMessage::query()
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->whereIn('sms_messages.organizer_id', $organizerIds)
            ->where('sms_conversations.phone', $phone)
            ->where('sms_messages.direction', SmsMessageDirection::OUTBOUND->name)
            ->whereNull('sms_messages.channel')
            ->where(fn ($query) => $query
                ->where('sms_messages.id', '>', $afterMessageId)
                ->orWhere(fn ($pending) => $pending
                    ->where('sms_messages.status', SmsMessageStatus::PENDING->name)
                    ->where('sms_messages.created_at', '>=', $pendingSince)))
            ->exists();
    }

    public function findUnansweredInbound(array $organizerIds, string $since, string $receivedBefore, int $limit): Collection
    {
        $messages = SmsMessage::query()
            ->select('sms_messages.*')
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->whereIn('sms_messages.organizer_id', $organizerIds)
            ->where('sms_messages.direction', SmsMessageDirection::INBOUND->name)
            ->whereNull('sms_messages.channel')
            ->where('sms_messages.created_at', '>=', $since)
            ->where('sms_messages.created_at', '<=', $receivedBefore)
            ->whereRaw('COALESCE(sms_messages.provider_timestamp, sms_messages.created_at) >= ?', [$since])
            ->where('sms_conversations.status', '!=', SmsConversationStatus::CLOSED->name)
            ->where('sms_conversations.is_team', false)
            ->whereNotExists(fn (Builder $reply) => $reply
                ->selectRaw('1')
                ->from('sms_messages as reply')
                ->whereColumn('reply.sms_conversation_id', 'sms_messages.sms_conversation_id')
                ->whereColumn('reply.id', '>', 'sms_messages.id')
                ->where('reply.direction', SmsMessageDirection::OUTBOUND->name)
                ->whereNotIn('reply.status', [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name]))
            ->whereNotExists(fn (Builder $optOut) => $optOut
                ->selectRaw('1')
                ->from('sms_opt_outs')
                ->whereColumn('sms_opt_outs.organizer_id', 'sms_conversations.organizer_id')
                ->whereColumn('sms_opt_outs.phone', 'sms_conversations.phone'))
            ->orderByDesc('sms_messages.id')
            ->limit($limit)
            ->get();

        return $this->handleResults($messages)->reverse()->values();
    }

    public function findRepliesTrafficSince(array $organizerIds, string $since, int $limit): Collection
    {
        $messages = SmsMessage::query()
            ->whereIn(SmsMessageDomainObjectAbstract::ORGANIZER_ID, $organizerIds)
            ->whereNull(SmsMessageDomainObjectAbstract::CHANNEL)
            ->where(SmsMessageDomainObjectAbstract::CREATED_AT, '>=', $since)
            ->where(fn (EloquentBuilder $query) => $query
                ->where(SmsMessageDomainObjectAbstract::DIRECTION, SmsMessageDirection::INBOUND->name)
                ->orWhereIn(SmsMessageDomainObjectAbstract::AUTHOR_TYPE, [SmsMessageAuthorType::HUMAN->name, SmsMessageAuthorType::AGENT->name])
                ->orWhereNotNull(SmsMessageDomainObjectAbstract::AUTO_REPLY_TO_MESSAGE_ID))
            ->orderByDesc(SmsMessageDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($messages)->reverse()->values();
    }

    public function findUndeliveredOutbound(array $organizerIds, string $since, string $pendingBefore, int $limit): Collection
    {
        $messages = SmsMessage::query()
            ->whereIn(SmsMessageDomainObjectAbstract::ORGANIZER_ID, $organizerIds)
            ->where(SmsMessageDomainObjectAbstract::DIRECTION, SmsMessageDirection::OUTBOUND->name)
            ->whereNull(SmsMessageDomainObjectAbstract::CHANNEL)
            ->where(SmsMessageDomainObjectAbstract::CREATED_AT, '>=', $since)
            ->where(fn (EloquentBuilder $query) => $query
                ->whereIn(SmsMessageDomainObjectAbstract::STATUS, [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name])
                ->orWhere(fn (EloquentBuilder $pending) => $pending
                    ->where(SmsMessageDomainObjectAbstract::STATUS, SmsMessageStatus::PENDING->name)
                    ->where(SmsMessageDomainObjectAbstract::CREATED_AT, '<=', $pendingBefore)))
            ->orderByDesc(SmsMessageDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($messages)->reverse()->values();
    }

    public function findLastInboundOrganizerId(array $organizerIds, string $phone): ?int
    {
        if ($organizerIds === []) {
            return null;
        }

        $organizerId = SmsMessage::query()
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->whereIn('sms_messages.organizer_id', array_values($organizerIds))
            ->where('sms_conversations.phone', $phone)
            ->where('sms_messages.direction', SmsMessageDirection::INBOUND->name)
            ->whereNull('sms_messages.channel')
            ->orderByDesc('sms_messages.id')
            ->value('sms_messages.organizer_id');

        return $organizerId === null ? null : (int) $organizerId;
    }

    public function findLatestAwaitingReply(int $organizerId, string $phone): ?SmsMessageDomainObject
    {
        $message = SmsMessage::query()
            ->select('sms_messages.*')
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->where('sms_messages.organizer_id', $organizerId)
            ->whereColumn('sms_conversations.organizer_id', 'sms_messages.organizer_id')
            ->where('sms_conversations.phone', $phone)
            ->where('sms_messages.direction', SmsMessageDirection::OUTBOUND->name)
            ->where('sms_messages.awaits_reply', true)
            ->whereNotIn('sms_messages.status', [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name])
            ->orderByDesc('sms_messages.id')
            ->first();

        return $message === null ? null : $this->handleSingleResult($message);
    }

    public function hasInboundFromPhoneAfter(int $organizerId, string $phone, int $afterMessageId): bool
    {
        return SmsMessage::query()
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->where('sms_messages.organizer_id', $organizerId)
            ->whereColumn('sms_conversations.organizer_id', 'sms_messages.organizer_id')
            ->where('sms_conversations.phone', $phone)
            ->where('sms_messages.direction', SmsMessageDirection::INBOUND->name)
            ->where('sms_messages.id', '>', $afterMessageId)
            ->exists();
    }
}
