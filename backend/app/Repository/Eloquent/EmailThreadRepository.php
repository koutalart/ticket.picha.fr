<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\DomainObjects\Generated\EmailThreadDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\DomainObjects\Status\EmailThreadStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\EmailThread;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailRecipientDTO;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailThreadSearchDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<EmailThreadDomainObject>
 */
class EmailThreadRepository extends BaseRepository implements EmailThreadRepositoryInterface
{
    private const MAX_CUSTOMER_ORDERS = 50;

    protected function getModel(): string
    {
        return EmailThread::class;
    }

    public function getDomainObject(): string
    {
        return EmailThreadDomainObject::class;
    }

    public function searchForOrganizer(int $organizerId, EmailThreadSearchDTO $search): LengthAwarePaginator
    {
        $query = EmailThread::query()
            ->where(EmailThreadDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->whereNotNull(EmailThreadDomainObjectAbstract::LAST_MESSAGE_AT);

        if ($search->filter === EmailThreadSearchDTO::FILTER_UNREAD) {
            $query->where(EmailThreadDomainObjectAbstract::UNREAD_COUNT, '>', 0);
        }

        if ($search->filter === EmailThreadSearchDTO::FILTER_NEEDS_HUMAN) {
            $query->where(EmailThreadDomainObjectAbstract::STATUS, EmailThreadStatus::NEEDS_HUMAN->name);
        }

        if ($search->filter === EmailThreadSearchDTO::FILTER_FAILED) {
            $query->whereIn(EmailThreadDomainObjectAbstract::LAST_MESSAGE_STATUS, [
                EmailMessageStatus::BOUNCED->name,
                EmailMessageStatus::COMPLAINED->name,
                EmailMessageStatus::FAILED->name,
            ]);
        }

        $term = trim((string) $search->query);
        if ($term !== '') {
            $like = $this->like($term);
            $query->where(fn ($inner) => $inner
                ->where(EmailThreadDomainObjectAbstract::CONTACT_NAME, 'ilike', $like)
                ->orWhere(EmailThreadDomainObjectAbstract::EMAIL, 'ilike', $like)
                ->orWhere(EmailThreadDomainObjectAbstract::LAST_MESSAGE_SUBJECT, 'ilike', $like));
        }

        $results = $query
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc(EmailThreadDomainObjectAbstract::ID)
            ->paginate(perPage: $search->perPage, page: $search->page);

        return $this->handleResults($results);
    }

    public function countUnreadForOrganizer(int $organizerId): int
    {
        return EmailThread::query()
            ->where(EmailThreadDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->where(EmailThreadDomainObjectAbstract::UNREAD_COUNT, '>', 0)
            ->count();
    }

    public function findOrdersByEmail(int $organizerId, string $email): Collection
    {
        $email = mb_strtolower(trim($email));

        $attendeeOrderIds = DB::table('attendees')
            ->whereNull('attendees.deleted_at')
            ->whereRaw('lower(attendees.email) = ?', [$email])
            ->select('attendees.order_id');

        return $this->organizerOrders($organizerId)
            ->whereNotIn('orders.status', [OrderStatus::RESERVED->name, OrderStatus::ABANDONED->name])
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(orders.email) = ?', [$email])
                ->orWhereIn('orders.id', $attendeeOrderIds))
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

    public function isKnownRecipient(int $organizerId, string $email): bool
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return false;
        }

        return $this->organizerContacts($organizerId)->whereRaw('lower(contacts.email) = ?', [$email])->exists()
            || $this->organizerOrders($organizerId)->whereRaw('lower(orders.email) = ?', [$email])->exists()
            || $this->organizerAttendees($organizerId)->whereRaw('lower(attendees.email) = ?', [$email])->exists();
    }

    public function isUnsubscribedRecipient(int $organizerId, string $email): bool
    {
        return $this->organizerContacts($organizerId)
            ->whereRaw('lower(contacts.email) = ?', [mb_strtolower(trim($email))])
            ->whereNotNull('contacts.email_unsubscribed_at')
            ->exists();
    }

    public function searchRecipients(int $organizerId, string $query, int $limit): Collection
    {
        $term = trim($query);
        $like = $this->like($term);

        $contacts = $this->organizerContacts($organizerId)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('contacts.email', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', contacts.first_name, contacts.last_name) ilike ?", [$like])))
            ->selectRaw("lower(contacts.email) as email, trim(concat_ws(' ', contacts.first_name, contacts.last_name)) as name, contacts.email_unsubscribed_at is not null as unsubscribed, 1 as priority");

        $orders = $this->organizerOrders($organizerId)
            ->whereNotNull('orders.email')
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('orders.email', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', orders.first_name, orders.last_name) ilike ?", [$like])))
            ->selectRaw("lower(orders.email) as email, trim(concat_ws(' ', orders.first_name, orders.last_name)) as name, false as unsubscribed, 2 as priority");

        $attendees = $this->organizerAttendees($organizerId)
            ->whereNotNull('attendees.email')
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('attendees.email', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', attendees.first_name, attendees.last_name) ilike ?", [$like])))
            ->selectRaw("lower(attendees.email) as email, trim(concat_ws(' ', attendees.first_name, attendees.last_name)) as name, false as unsubscribed, 3 as priority");

        $rows = DB::query()
            ->fromSub($contacts->unionAll($orders)->unionAll($attendees), 'recipients')
            ->where('email', '<>', '')
            ->selectRaw("email, (array_agg(name order by priority) filter (where name <> ''))[1] as name, bool_or(unsubscribed) as unsubscribed")
            ->groupBy('email')
            ->orderBy('email')
            ->limit($limit)
            ->get();

        $threadIds = EmailThread::query()
            ->where(EmailThreadDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->whereIn(EmailThreadDomainObjectAbstract::EMAIL, $rows->pluck('email')->all())
            ->pluck(EmailThreadDomainObjectAbstract::ID, EmailThreadDomainObjectAbstract::EMAIL);

        return $rows->map(fn (object $row) => new EmailRecipientDTO(
            email: (string) $row->email,
            name: $row->name !== null && $row->name !== '' ? (string) $row->name : null,
            threadId: isset($threadIds[$row->email]) ? (int) $threadIds[$row->email] : null,
            unsubscribed: (bool) $row->unsubscribed,
        ));
    }

    public function isAccountUserEmail(int $accountId, string $email): bool
    {
        return DB::table('users')
            ->join('account_users', 'account_users.user_id', '=', 'users.id')
            ->where('account_users.account_id', $accountId)
            ->whereNull('account_users.deleted_at')
            ->whereRaw('lower(users.email) = ?', [mb_strtolower(trim($email))])
            ->exists();
    }

    public function findNameForEmail(int $organizerId, string $email): ?string
    {
        $recipient = $this->searchRecipients($organizerId, $email, 5)
            ->first(fn (EmailRecipientDTO $row) => $row->email === mb_strtolower(trim($email)));

        return $recipient?->name !== null ? mb_substr($recipient->name, 0, 150) : null;
    }

    private function organizerContacts(int $organizerId): Builder
    {
        return DB::table('contacts')
            ->where('contacts.organizer_id', $organizerId)
            ->whereNull('contacts.merged_into_contact_id')
            ->whereNotNull('contacts.email');
    }

    private function organizerOrders(int $organizerId): Builder
    {
        return DB::table('orders')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->where('events.organizer_id', $organizerId)
            ->whereNull('orders.deleted_at')
            ->whereNull('events.deleted_at');
    }

    private function organizerAttendees(int $organizerId): Builder
    {
        return DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->where('events.organizer_id', $organizerId)
            ->whereNull('attendees.deleted_at')
            ->whereNull('events.deleted_at');
    }

    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }
}
