<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\SmsDraftSource;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Models\SmsConversation;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsConversationSearchDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<SmsConversationDomainObject>
 */
class SmsConversationRepository extends BaseRepository implements SmsConversationRepositoryInterface
{
    private const MAX_CUSTOMER_ORDERS = 50;

    protected function getModel(): string
    {
        return SmsConversation::class;
    }

    public function getDomainObject(): string
    {
        return SmsConversationDomainObject::class;
    }

    public function searchForOrganizer(int $organizerId, SmsConversationSearchDTO $search): LengthAwarePaginator
    {
        $query = SmsConversation::query()
            ->where(SmsConversationDomainObjectAbstract::ORGANIZER_ID, $organizerId);

        if ($search->filter === SmsConversationSearchDTO::FILTER_UNREAD) {
            $query->where(SmsConversationDomainObjectAbstract::UNREAD_COUNT, '>', 0);
        }

        if ($search->filter === SmsConversationSearchDTO::FILTER_NEEDS_HUMAN) {
            $query->where(SmsConversationDomainObjectAbstract::STATUS, SmsConversationStatus::NEEDS_HUMAN->name);
        }

        $term = trim((string) $search->query);
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $digits = preg_replace('/\D+/', '', $term) ?? '';

            $query->where(function ($inner) use ($like, $digits) {
                $inner->where(SmsConversationDomainObjectAbstract::CONTACT_NAME, 'ilike', $like)
                    ->orWhere(SmsConversationDomainObjectAbstract::PHONE, 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $inner->orWhere(SmsConversationDomainObjectAbstract::PHONE, 'like', '%'.$digits.'%');
                }
            });
        }

        $results = $query
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc(SmsConversationDomainObjectAbstract::ID)
            ->paginate(perPage: $search->perPage, page: $search->page);

        return $this->handleResults($results);
    }

    public function countUnreadForOrganizer(int $organizerId): int
    {
        return SmsConversation::query()
            ->where(SmsConversationDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->where(SmsConversationDomainObjectAbstract::UNREAD_COUNT, '>', 0)
            ->count();
    }

    public function claimInboundNotification(int $conversationId, string $notifiedBefore, string $now): bool
    {
        return SmsConversation::query()
            ->whereKey($conversationId)
            ->where(function ($query) use ($notifiedBefore) {
                $query->whereNull(SmsConversationDomainObjectAbstract::INBOUND_NOTIFIED_AT)
                    ->orWhere(SmsConversationDomainObjectAbstract::INBOUND_NOTIFIED_AT, '<=', $notifiedBefore);
            })
            ->update([SmsConversationDomainObjectAbstract::INBOUND_NOTIFIED_AT => $now]) > 0;
    }

    public function findContactNameByPhone(int $organizerId, string $phone): ?string
    {
        $order = $this->findOrdersByPhone($organizerId, $phone)->first();

        if ($order === null) {
            return null;
        }

        $name = trim(($order->firstName ?? '').' '.($order->lastName ?? ''));

        return $name !== '' ? mb_substr($name, 0, 150) : null;
    }

    public function findOrdersByPhone(int $organizerId, string $phone): Collection
    {
        $suffix = SmsPhoneNormalizer::matchingSuffix($phone);

        if ($suffix === null) {
            return collect();
        }

        $matchingOrderIds = DB::table('question_answers')
            ->join('questions', 'questions.id', '=', 'question_answers.question_id')
            ->where('questions.type', 'PHONE')
            ->whereNull('question_answers.deleted_at')
            ->whereRaw("right(regexp_replace(question_answers.answer::text, '\\D', '', 'g'), 9) = ?", [$suffix])
            ->select('question_answers.order_id')
            ->union(
                DB::table('box_office_sales')
                    ->whereNotNull('box_office_sales.order_id')
                    ->whereRaw("right(regexp_replace(coalesce(box_office_sales.phone, ''), '\\D', '', 'g'), 9) = ?", [$suffix])
                    ->select('box_office_sales.order_id')
            );

        $phoneOrderIds = fn (Builder $sub) => $sub->fromSub($matchingOrderIds, 'matching')->select('order_id');

        $matchingEmails = $this->organizerOrders($organizerId)
            ->whereIn('orders.id', $phoneOrderIds)
            ->whereNotNull('orders.email')
            ->where('orders.email', '<>', '')
            ->selectRaw('lower(orders.email)');

        return $this->organizerOrders($organizerId)
            ->whereNotIn('orders.status', [OrderStatus::RESERVED->name, OrderStatus::ABANDONED->name])
            ->where(fn (Builder $query) => $query
                ->whereIn('orders.id', $phoneOrderIds)
                ->orWhereIn(DB::raw('lower(orders.email)'), $matchingEmails))
            ->orderByDesc('orders.created_at')
            ->limit(self::MAX_CUSTOMER_ORDERS)
            ->get([
                'orders.id',
                'orders.public_id',
                'orders.event_id',
                'events.title as event_title',
                'events.start_date as event_start_date',
                'orders.first_name',
                'orders.last_name',
                'orders.email',
                'orders.total_gross',
                'orders.currency',
                'orders.status',
                'orders.created_at',
            ])
            ->map(fn (object $row) => new SmsCustomerOrderDTO(
                orderId: (int) $row->id,
                publicId: (string) $row->public_id,
                eventId: (int) $row->event_id,
                eventTitle: (string) $row->event_title,
                eventStartDate: $row->event_start_date,
                firstName: $row->first_name,
                lastName: $row->last_name,
                email: $row->email,
                totalGross: (float) $row->total_gross,
                currency: (string) $row->currency,
                status: (string) $row->status,
                createdAt: (string) $row->created_at,
            ));
    }

    private function organizerOrders(int $organizerId): Builder
    {
        return DB::table('orders')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->where('events.organizer_id', $organizerId)
            ->whereNull('orders.deleted_at')
            ->whereNull('events.deleted_at');
    }

    public function findWaitingAgentDrafts(array $organizerIds, string $since, string $generatedBefore, int $limit): Collection
    {
        if ($organizerIds === []) {
            return collect();
        }

        $conversations = SmsConversation::query()
            ->whereIn('organizer_id', $organizerIds)
            ->where('draft_source', SmsDraftSource::AGENT->name)
            ->whereNotNull('draft_reply')
            ->where('draft_generated_at', '>=', $since)
            ->where('draft_generated_at', '<=', $generatedBefore)
            ->where('status', '!=', SmsConversationStatus::CLOSED->name)
            ->whereNotExists(fn (Builder $reply) => $reply
                ->selectRaw('1')
                ->from('sms_messages')
                ->whereColumn('sms_messages.sms_conversation_id', 'sms_conversations.id')
                ->where('sms_messages.direction', SmsMessageDirection::OUTBOUND->name)
                ->whereColumn('sms_messages.created_at', '>=', 'sms_conversations.draft_generated_at')
                ->whereNotIn('sms_messages.status', [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name]))
            ->orderBy('draft_generated_at')
            ->limit($limit)
            ->get();

        return $this->handleResults($conversations);
    }

    public function findLatestTeamConversationOrganizerId(array $organizerIds, string $phone): ?int
    {
        if ($organizerIds === []) {
            return null;
        }

        $organizerId = SmsConversation::query()
            ->whereIn(SmsConversationDomainObjectAbstract::ORGANIZER_ID, array_values($organizerIds))
            ->where(SmsConversationDomainObjectAbstract::PHONE, $phone)
            ->where(SmsConversationDomainObjectAbstract::IS_TEAM, true)
            ->whereNotNull(SmsConversationDomainObjectAbstract::LAST_MESSAGE_AT)
            ->orderByDesc(SmsConversationDomainObjectAbstract::LAST_MESSAGE_AT)
            ->orderByDesc(SmsConversationDomainObjectAbstract::ID)
            ->value(SmsConversationDomainObjectAbstract::ORGANIZER_ID);

        return $organizerId === null ? null : (int) $organizerId;
    }

    public function findWithFrenchOverseasPhones(): Collection
    {
        return $this->handleResults(SmsConversation::query()
            ->whereRaw("phone ~ '^\\+33(639|692|693|262|263|269)[0-9]{6}$'")
            ->orderBy(SmsConversationDomainObjectAbstract::ID)
            ->get());
    }

    public function mergeInto(int $sourceId, int $targetId): int
    {
        return DB::transaction(function () use ($sourceId, $targetId) {
            $source = SmsConversation::query()->findOrFail($sourceId);
            $target = SmsConversation::query()->findOrFail($targetId);

            $moved = DB::table('sms_messages')->where('sms_conversation_id', $sourceId)->update(['sms_conversation_id' => $targetId]);
            DB::table('live_chat_sessions')->where('sms_conversation_id', $sourceId)->update(['sms_conversation_id' => $targetId]);
            DB::table('sms_assistant_calls')->where('sms_conversation_id', $sourceId)->update(['sms_conversation_id' => $targetId]);

            $attributes = [
                SmsConversationDomainObjectAbstract::UNREAD_COUNT => (int) $target->unread_count + (int) $source->unread_count,
                SmsConversationDomainObjectAbstract::LINE_SETTINGS_ID => $target->line_settings_id ?? $source->line_settings_id,
                SmsConversationDomainObjectAbstract::CONTACT_NAME => $target->contact_name ?? $source->contact_name,
            ];
            if ($source->last_message_at !== null && ($target->last_message_at === null || $source->last_message_at > $target->last_message_at)) {
                $attributes[SmsConversationDomainObjectAbstract::LAST_MESSAGE_AT] = $source->last_message_at;
                $attributes[SmsConversationDomainObjectAbstract::LAST_MESSAGE_PREVIEW] = $source->last_message_preview;
                $attributes[SmsConversationDomainObjectAbstract::LAST_MESSAGE_DIRECTION] = $source->last_message_direction;
            }

            SmsConversation::query()->whereKey($targetId)->update($attributes);
            SmsConversation::query()->whereKey($sourceId)->delete();

            return $moved;
        });
    }
}
