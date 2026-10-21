<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
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

        return DB::table('orders')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->where('events.organizer_id', $organizerId)
            ->whereNull('orders.deleted_at')
            ->whereNull('events.deleted_at')
            ->whereNotIn('orders.status', [OrderStatus::RESERVED->name, OrderStatus::ABANDONED->name])
            ->whereIn('orders.id', fn (Builder $sub) => $sub->fromSub($matchingOrderIds, 'matching')->select('order_id'))
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
}
