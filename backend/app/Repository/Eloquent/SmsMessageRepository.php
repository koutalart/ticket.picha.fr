<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Models\SmsMessage;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
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

    public function findLastOutboundOrganizerId(array $organizerIds, string $phone, string $since): ?int
    {
        if ($organizerIds === []) {
            return null;
        }

        $sentAt = 'COALESCE(sms_messages.provider_timestamp, sms_messages.created_at)';

        $organizerId = SmsMessage::query()
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->whereIn('sms_messages.organizer_id', $organizerIds)
            ->whereColumn('sms_conversations.organizer_id', 'sms_messages.organizer_id')
            ->where('sms_conversations.phone', $phone)
            ->where('sms_messages.direction', SmsMessageDirection::OUTBOUND->name)
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
            ->where(fn ($query) => $query
                ->where('sms_messages.id', '>', $afterMessageId)
                ->orWhere(fn ($pending) => $pending
                    ->where('sms_messages.status', SmsMessageStatus::PENDING->name)
                    ->where('sms_messages.created_at', '>=', $pendingSince)))
            ->exists();
    }
}
