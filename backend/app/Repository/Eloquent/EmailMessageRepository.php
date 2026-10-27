<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\Models\EmailMessage;
use HiEvents\Repository\Interfaces\EmailMessageRepositoryInterface;
use HiEvents\Services\Domain\Email\Inbox\DTO\HistoricalOutgoingMessageDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<EmailMessageDomainObject>
 */
class EmailMessageRepository extends BaseRepository implements EmailMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return EmailMessage::class;
    }

    public function getDomainObject(): string
    {
        return EmailMessageDomainObject::class;
    }

    public function findUnimportedOutgoingMessages(int $afterId, string $sentBefore, ?int $organizerId, int $limit): Collection
    {
        return DB::table('outgoing_messages')
            ->join('events', 'events.id', '=', 'outgoing_messages.event_id')
            ->leftJoin('messages', 'messages.id', '=', 'outgoing_messages.message_id')
            ->whereNull('outgoing_messages.deleted_at')
            ->whereNull('events.deleted_at')
            ->whereNotNull('events.organizer_id')
            ->where('outgoing_messages.id', '>', $afterId)
            ->where('outgoing_messages.created_at', '<', $sentBefore)
            ->when($organizerId !== null, fn ($query) => $query->where('events.organizer_id', $organizerId))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('email_messages')
                ->whereColumn('email_messages.outgoing_message_id', 'outgoing_messages.id'))
            ->orderBy('outgoing_messages.id')
            ->limit($limit)
            ->get([
                'outgoing_messages.id',
                'events.account_id',
                'events.organizer_id',
                'outgoing_messages.event_id',
                'messages.order_id',
                'messages.sent_by_user_id',
                'outgoing_messages.recipient',
                'outgoing_messages.subject',
                'messages.message',
                'outgoing_messages.status',
                'outgoing_messages.created_at',
            ])
            ->map(fn (object $row) => new HistoricalOutgoingMessageDTO(
                outgoingMessageId: (int) $row->id,
                accountId: (int) $row->account_id,
                organizerId: (int) $row->organizer_id,
                eventId: (int) $row->event_id,
                orderId: $row->order_id !== null ? (int) $row->order_id : null,
                sentByUserId: $row->sent_by_user_id !== null ? (int) $row->sent_by_user_id : null,
                recipient: (string) $row->recipient,
                subject: $row->subject,
                messageHtml: $row->message,
                status: (string) $row->status,
                createdAt: (string) $row->created_at,
            ));
    }

    public function findFirstLoggedAt(string $source): ?string
    {
        $value = EmailMessage::query()->where('source', $source)->min('created_at');

        return $value !== null ? (string) $value : null;
    }
}
