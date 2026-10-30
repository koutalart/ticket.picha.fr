<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Models\SmsMessage;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsLastSenderDTO;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    public function findLastSender(
        array $organizerIds,
        string $phone,
        string $since,
        ?int $lineSettingsId = null,
        ?string $until = null,
    ): ?SmsLastSenderDTO {
        if ($organizerIds === []) {
            return null;
        }

        $sentAt = 'COALESCE(sms_messages.provider_timestamp, sms_messages.created_at)';

        $message = SmsMessage::query()
            ->when($lineSettingsId !== null, fn (EloquentBuilder $query) => $query->where('sms_messages.line_settings_id', $lineSettingsId))
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->whereIn('sms_messages.organizer_id', $organizerIds)
            ->whereColumn('sms_conversations.organizer_id', 'sms_messages.organizer_id')
            ->where('sms_conversations.phone', $phone)
            ->where('sms_messages.is_supervision', false)
            ->where('sms_messages.direction', SmsMessageDirection::OUTBOUND->name)
            ->whereNull('sms_messages.channel')
            ->whereNotIn('sms_messages.status', [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name])
            ->whereRaw($sentAt.' >= ?', [$since])
            ->when($until !== null, fn (EloquentBuilder $query) => $query->whereRaw($sentAt.' <= ?', [$until]))
            ->orderByDesc('sms_conversations.is_team')
            ->orderByRaw($sentAt.' DESC')
            ->orderByDesc('sms_messages.id')
            ->selectRaw('sms_messages.organizer_id as organizer_id, sms_conversations.is_team as is_team, '.$sentAt.' as sent_at')
            ->toBase()
            ->first();

        if ($message !== null && (bool) $message->is_team) {
            return new SmsLastSenderDTO(
                organizerId: (int) $message->organizer_id,
                sentAt: Carbon::parse((string) $message->sent_at)->toDateTimeString(),
                isTeam: true,
            );
        }

        $recipientSentAt = 'COALESCE(sms_campaign_recipients.sent_at, sms_campaign_recipients.delivered_at)';

        $recipient = DB::table('sms_campaign_recipients')
            ->join('sms_campaigns', 'sms_campaigns.id', '=', 'sms_campaign_recipients.sms_campaign_id')
            ->whereIn('sms_campaigns.organizer_id', $organizerIds)
            ->whereNull('sms_campaigns.deleted_at')
            ->where('sms_campaign_recipients.phone', $phone)
            ->whereIn('sms_campaign_recipients.status', [SmsCampaignRecipientStatus::SENT->value, SmsCampaignRecipientStatus::DELIVERED->value])
            ->whereRaw($recipientSentAt.' >= ?', [$since])
            ->when($until !== null, fn (Builder $query) => $query->whereRaw($recipientSentAt.' <= ?', [$until]))
            ->orderByRaw($recipientSentAt.' DESC')
            ->orderByDesc('sms_campaign_recipients.id')
            ->selectRaw('sms_campaigns.organizer_id as organizer_id, '.$recipientSentAt.' as sent_at')
            ->first();

        $last = collect([$message, $recipient])
            ->filter()
            ->sortByDesc(fn (object $row) => Carbon::parse((string) $row->sent_at)->getTimestamp())
            ->first();

        return $last === null ? null : new SmsLastSenderDTO(
            organizerId: (int) $last->organizer_id,
            sentAt: Carbon::parse((string) $last->sent_at)->toDateTimeString(),
        );
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

    public function findQueuedForSync(string $createdSince, string $checkedBefore, int $limit): Collection
    {
        $messages = SmsMessage::query()
            ->whereNotNull(SmsMessageDomainObjectAbstract::QUEUE_MESSAGE_ID)
            ->whereIn(SmsMessageDomainObjectAbstract::STATUS, [SmsMessageStatus::QUEUED->name, SmsMessageStatus::PENDING->name])
            ->where(SmsMessageDomainObjectAbstract::CREATED_AT, '>=', $createdSince)
            ->where(fn (EloquentBuilder $query) => $query
                ->whereNull(SmsMessageDomainObjectAbstract::QUEUE_CHECKED_AT)
                ->orWhere(SmsMessageDomainObjectAbstract::QUEUE_CHECKED_AT, '<=', $checkedBefore))
            ->orderByRaw('queue_checked_at asc nulls first')
            ->orderBy(SmsMessageDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($messages);
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

    public function hasInboundFromPhoneSince(int $organizerId, string $phone, string $since, int $exceptMessageId): bool
    {
        return SmsMessage::query()
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->where('sms_messages.organizer_id', $organizerId)
            ->whereColumn('sms_conversations.organizer_id', 'sms_messages.organizer_id')
            ->where('sms_conversations.phone', $phone)
            ->where('sms_messages.direction', SmsMessageDirection::INBOUND->name)
            ->where('sms_messages.created_at', '>=', $since)
            ->where('sms_messages.id', '!=', $exceptMessageId)
            ->exists();
    }
}
