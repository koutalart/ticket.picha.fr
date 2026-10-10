<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\ContactConsentSource;
use HiEvents\DomainObjects\Enums\ContactSource;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\Contact;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactFilterDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactSummaryDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactTimelineItemDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ContactRepository extends BaseRepository implements ContactRepositoryInterface
{
    protected function getModel(): string
    {
        return Contact::class;
    }

    public function getDomainObject(): string
    {
        return ContactDomainObject::class;
    }

    public function syncFromOrders(int $organizerId, ?array $emails = null): int
    {
        if ($emails === []) {
            return 0;
        }

        $syncStartedAt = DB::selectOne('SELECT clock_timestamp()::timestamp AS started_at')->started_at;
        $emailsJson = $emails === null ? null : json_encode(array_values($emails));

        $sql = <<<'SQL'
WITH params AS (
    SELECT ?::bigint AS organizer_id, ?::jsonb AS emails
),
valid_orders AS (
    SELECT o.*, lower(trim(o.email)) AS normalized_email
    FROM orders o
    JOIN events e ON e.id = o.event_id
    CROSS JOIN params p
    WHERE e.organizer_id = p.organizer_id
      AND o.deleted_at IS NULL
      AND o.status IN (?, ?)
),
people AS (
    SELECT o.normalized_email AS email, o.first_name, o.last_name, o.created_at AS seen_at, o.event_id
    FROM valid_orders o
    WHERE o.email IS NOT NULL AND trim(o.email) <> '' AND o.normalized_email NOT LIKE '%.invalid'
    UNION ALL
    SELECT lower(trim(a.email)), a.first_name, a.last_name, a.created_at, a.event_id
    FROM attendees a
    JOIN valid_orders o ON o.id = a.order_id
    WHERE a.deleted_at IS NULL AND a.email IS NOT NULL AND trim(a.email) <> '' AND lower(trim(a.email)) NOT LIKE '%.invalid'
),
scoped_people AS (
    SELECT people.*
    FROM people CROSS JOIN params p
    WHERE p.emails IS NULL OR people.email IN (SELECT jsonb_array_elements_text(p.emails))
),
latest_names AS (
    SELECT DISTINCT ON (email) email, nullif(trim(first_name), '') AS first_name, nullif(trim(last_name), '') AS last_name
    FROM scoped_people
    ORDER BY email, seen_at DESC
),
event_stats AS (
    SELECT email, count(DISTINCT event_id) AS events_count
    FROM scoped_people
    GROUP BY email
),
order_stats AS (
    SELECT normalized_email AS email,
           count(*) AS orders_count,
           sum(coalesce(total_gross, 0) - coalesce(total_refunded, 0)) AS total_spent,
           min(created_at) AS first_order_at,
           max(created_at) AS last_order_at,
           max(opted_into_marketing_at) AS email_consent_at
    FROM valid_orders
    WHERE normalized_email IN (SELECT email FROM event_stats)
    GROUP BY normalized_email
),
attended_stats AS (
    SELECT lower(trim(a.email)) AS email, count(DISTINCT a.event_id) AS attended_events_count
    FROM attendees a
    JOIN valid_orders o ON o.id = a.order_id
    WHERE a.deleted_at IS NULL
      AND lower(trim(a.email)) IN (SELECT email FROM event_stats)
      AND EXISTS (SELECT 1 FROM attendee_check_ins c WHERE c.attendee_id = a.id AND c.deleted_at IS NULL)
    GROUP BY lower(trim(a.email))
)
INSERT INTO contacts (
    organizer_id, email, first_name, last_name, source,
    orders_count, events_count, attended_events_count, total_spent,
    first_order_at, last_order_at, email_consent_at, email_consent_source,
    last_synced_at, created_at, updated_at
)
SELECT p.organizer_id, n.email, n.first_name, n.last_name,
       CASE WHEN os.email IS NULL THEN ? ELSE ? END,
       coalesce(os.orders_count, 0), es.events_count, coalesce(ats.attended_events_count, 0), coalesce(os.total_spent, 0),
       os.first_order_at, os.last_order_at, os.email_consent_at,
       CASE WHEN os.email_consent_at IS NULL THEN NULL ELSE ? END,
       clock_timestamp(), now(), now()
FROM latest_names n
CROSS JOIN params p
JOIN event_stats es ON es.email = n.email
LEFT JOIN order_stats os ON os.email = n.email
LEFT JOIN attended_stats ats ON ats.email = n.email
ON CONFLICT (organizer_id, email) WHERE email IS NOT NULL DO UPDATE SET
    first_name = coalesce(contacts.first_name, EXCLUDED.first_name),
    last_name = coalesce(contacts.last_name, EXCLUDED.last_name),
    source = CASE WHEN contacts.source = ? THEN EXCLUDED.source ELSE contacts.source END,
    orders_count = EXCLUDED.orders_count,
    events_count = EXCLUDED.events_count,
    attended_events_count = EXCLUDED.attended_events_count,
    total_spent = EXCLUDED.total_spent,
    first_order_at = EXCLUDED.first_order_at,
    last_order_at = EXCLUDED.last_order_at,
    email_consent_source = CASE
        WHEN EXCLUDED.email_consent_at > coalesce(contacts.email_consent_at, '-infinity'::timestamp) THEN EXCLUDED.email_consent_source
        ELSE contacts.email_consent_source END,
    email_consent_at = CASE
        WHEN EXCLUDED.email_consent_at > coalesce(contacts.email_consent_at, '-infinity'::timestamp) THEN EXCLUDED.email_consent_at
        ELSE contacts.email_consent_at END,
    last_synced_at = clock_timestamp(),
    updated_at = now()
SQL;

        $synced = DB::affectingStatement($sql, [
            $organizerId,
            $emailsJson,
            OrderStatus::COMPLETED->name,
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            ContactSource::ATTENDEE->name,
            ContactSource::ORDER->name,
            ContactConsentSource::CHECKOUT->name,
            ContactSource::ATTENDEE->name,
        ]);

        $this->resetStaleStatistics($organizerId, $emails, $syncStartedAt);

        return $synced;
    }

    public function paginateForOrganizer(int $organizerId, ContactFilterDTO $filter, QueryParamsDTO $params): LengthAwarePaginator
    {
        $sortBy = $this->validateSortColumn($params->sort_by, ContactDomainObject::class);
        $direction = $this->validateSortDirection($params->sort_direction, ContactDomainObject::class);

        $query = $this->model instanceof Builder ? $this->model : $this->model->newQuery();

        $this->model = $this->applyFilter($query, $organizerId, $filter)
            ->orderByRaw(sprintf('%s %s NULLS LAST', $sortBy, $direction))
            ->orderBy('id', 'desc');

        return $this->paginate(min($params->per_page ?? 25, 100));
    }

    public function getSummaryForOrganizer(int $organizerId, ContactFilterDTO $filter): ContactSummaryDTO
    {
        $row = $this->applyFilter(Contact::query(), $organizerId, $filter)
            ->toBase()
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE ' . self::emailConsentSql() . ') AS email_consent')
            ->selectRaw('count(*) FILTER (WHERE ' . self::smsConsentSql() . ') AS sms_consent')
            ->selectRaw('count(*) FILTER (WHERE events_count >= 2) AS repeat_customers')
            ->selectRaw('coalesce(sum(total_spent), 0) AS total_spent')
            ->first();

        return new ContactSummaryDTO(
            total: (int) $row->total,
            email_consent: (int) $row->email_consent,
            sms_consent: (int) $row->sms_consent,
            repeat_customers: (int) $row->repeat_customers,
            total_spent: (float) $row->total_spent,
        );
    }

    public function lazyForOrganizer(int $organizerId, ContactFilterDTO $filter): LazyCollection
    {
        return $this->applyFilter(Contact::query(), $organizerId, $filter)
            ->with('contact_tags')
            ->orderBy('id')
            ->lazyById(500)
            ->map(fn(Contact $contact) => $this->handleSingleResult($contact));
    }

    public function getTimeline(int $organizerId, string $email): Collection
    {
        $sql = <<<SQL
WITH params AS (SELECT ?::bigint AS organizer_id, ?::text AS email),
organizer_events AS (
    SELECT e.id, e.title FROM events e CROSS JOIN params p WHERE e.organizer_id = p.organizer_id
),
contact_orders AS (
    SELECT o.* FROM orders o
    JOIN organizer_events e ON e.id = o.event_id
    CROSS JOIN params p
    WHERE o.deleted_at IS NULL AND o.status IN (?, ?, ?)
      AND (lower(trim(o.email)) = p.email
           OR EXISTS (SELECT 1 FROM attendees a WHERE a.order_id = o.id AND a.deleted_at IS NULL AND lower(trim(a.email)) = p.email))
)
SELECT 'ORDER' AS type, o.created_at AS occurred_at, o.event_id, e.title AS event_title, o.id AS order_id,
       o.public_id AS title,
       (SELECT string_agg(trim(coalesce(a.first_name, '') || ' ' || coalesce(a.last_name, '')), ', ' ORDER BY a.id)
          FROM attendees a WHERE a.order_id = o.id AND a.deleted_at IS NULL) AS detail,
       (o.total_gross - coalesce(o.total_refunded, 0))::float AS amount, o.currency, o.status
FROM contact_orders o JOIN organizer_events e ON e.id = o.event_id CROSS JOIN params p
WHERE lower(trim(o.email)) = p.email
UNION ALL
SELECT 'TICKET_RECEIVED', a.created_at, a.event_id, e.title, a.order_id, a.public_id,
       trim(coalesce(o.first_name, '') || ' ' || coalesce(o.last_name, '')), NULL, NULL, a.status
FROM attendees a
JOIN contact_orders o ON o.id = a.order_id
JOIN organizer_events e ON e.id = a.event_id
CROSS JOIN params p
WHERE a.deleted_at IS NULL AND lower(trim(a.email)) = p.email AND lower(trim(o.email)) <> p.email
UNION ALL
SELECT 'CHECK_IN', c.created_at, c.event_id, e.title, c.order_id, a.public_id,
       cl.name, NULL, NULL, NULL
FROM attendee_check_ins c
JOIN attendees a ON a.id = c.attendee_id
JOIN organizer_events e ON e.id = c.event_id
LEFT JOIN check_in_lists cl ON cl.id = c.check_in_list_id
CROSS JOIN params p
WHERE c.deleted_at IS NULL AND lower(trim(a.email)) = p.email
UNION ALL
SELECT 'MESSAGE', om.created_at, om.event_id, e.title, NULL, om.subject, NULL, NULL, NULL, om.status
FROM outgoing_messages om
JOIN organizer_events e ON e.id = om.event_id
CROSS JOIN params p
WHERE om.deleted_at IS NULL AND lower(trim(om.recipient)) = p.email
UNION ALL
SELECT 'ANSWER', qa.created_at, q.event_id, e.title, qa.order_id, q.title,
       CASE jsonb_typeof(qa.answer)
           WHEN 'array' THEN (SELECT string_agg(value, ', ') FROM jsonb_array_elements_text(qa.answer))
           WHEN 'object' THEN (SELECT string_agg(value, ', ') FROM jsonb_each_text(qa.answer))
           ELSE qa.answer #>> '{}' END,
       NULL, NULL, NULL
FROM question_answers qa
JOIN questions q ON q.id = qa.question_id
JOIN contact_orders o ON o.id = qa.order_id
JOIN organizer_events e ON e.id = q.event_id
CROSS JOIN params p
WHERE qa.deleted_at IS NULL
  AND (
    (qa.attendee_id IS NULL AND lower(trim(o.email)) = p.email)
    OR EXISTS (SELECT 1 FROM attendees a WHERE a.id = qa.attendee_id AND lower(trim(a.email)) = p.email)
  )
ORDER BY occurred_at DESC
LIMIT 500
SQL;

        $rows = DB::select($sql, [
            $organizerId,
            $email,
            OrderStatus::COMPLETED->name,
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            OrderStatus::CANCELLED->name,
        ]);

        return collect($rows)->map(fn(object $row) => new ContactTimelineItemDTO(
            type: $row->type,
            occurred_at: $row->occurred_at,
            event_id: $row->event_id,
            event_title: $row->event_title,
            order_id: $row->order_id,
            title: $row->title,
            detail: $row->detail,
            amount: $row->amount,
            currency: $row->currency,
            status: $row->status,
        ));
    }

    public static function emailConsentSql(): string
    {
        return '(email_consent_at IS NOT NULL AND (email_unsubscribed_at IS NULL OR email_unsubscribed_at < email_consent_at))';
    }

    public static function smsConsentSql(): string
    {
        return '(phone IS NOT NULL AND sms_consent_at IS NOT NULL AND (sms_unsubscribed_at IS NULL OR sms_unsubscribed_at < sms_consent_at))';
    }

    private function applyFilter(Builder $query, int $organizerId, ContactFilterDTO $filter): Builder
    {
        $validStatuses = [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name];

        $query->where('contacts.organizer_id', $organizerId)
            ->whereNull('contacts.merged_into_contact_id');

        if ($filter->query !== null) {
            $like = '%' . addcslashes($filter->query, '%_\\') . '%';
            $query->where(fn(Builder $q) => $q
                ->whereRaw("(coalesce(first_name, '') || ' ' || coalesce(last_name, '')) ILIKE ?", [$like])
                ->orWhere('email', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like));
        }

        if ($filter->eventId !== null) {
            $eventId = $filter->eventId;
            $query->where(fn(Builder $q) => $q
                ->whereExists(fn($sub) => $sub->selectRaw('1')
                    ->from('orders as o')
                    ->join('events as e', 'e.id', '=', 'o.event_id')
                    ->where('o.event_id', $eventId)
                    ->whereColumn('e.organizer_id', 'contacts.organizer_id')
                    ->whereNull('o.deleted_at')
                    ->whereIn('o.status', $validStatuses)
                    ->whereRaw('lower(trim(o.email)) = contacts.email'))
                ->orWhereExists(fn($sub) => $sub->selectRaw('1')
                    ->from('attendees as a')
                    ->join('orders as o', 'o.id', '=', 'a.order_id')
                    ->join('events as e', 'e.id', '=', 'a.event_id')
                    ->where('a.event_id', $eventId)
                    ->whereColumn('e.organizer_id', 'contacts.organizer_id')
                    ->whereNull('a.deleted_at')
                    ->whereIn('o.status', $validStatuses)
                    ->whereRaw('lower(trim(a.email)) = contacts.email')));
        }

        if ($filter->attendedOnly) {
            if ($filter->eventId !== null) {
                $eventId = $filter->eventId;
                $query->whereExists(fn($sub) => $sub->selectRaw('1')
                    ->from('attendee_check_ins as c')
                    ->join('attendees as a', 'a.id', '=', 'c.attendee_id')
                    ->join('events as e', 'e.id', '=', 'c.event_id')
                    ->where('c.event_id', $eventId)
                    ->whereColumn('e.organizer_id', 'contacts.organizer_id')
                    ->whereNull('c.deleted_at')
                    ->whereRaw('lower(trim(a.email)) = contacts.email'));
            } else {
                $query->where('attended_events_count', '>', 0);
            }
        }

        if ($filter->tagId !== null) {
            $tagId = $filter->tagId;
            $query->whereExists(fn($sub) => $sub->selectRaw('1')
                ->from('contact_tag_assignments as cta')
                ->join('contact_tags as ct', 'ct.id', '=', 'cta.contact_tag_id')
                ->whereColumn('cta.contact_id', 'contacts.id')
                ->whereColumn('ct.organizer_id', 'contacts.organizer_id')
                ->where('cta.contact_tag_id', $tagId));
        }

        if ($filter->emailConsentOnly) {
            $query->whereRaw(self::emailConsentSql());
        }

        if ($filter->smsConsentOnly) {
            $query->whereRaw(self::smsConsentSql());
        }

        if ($filter->withPhoneOnly) {
            $query->whereNotNull('phone');
        }

        return $query;
    }

    private function resetStaleStatistics(int $organizerId, ?array $emails, string $syncStartedAt): void
    {
        $query = DB::table('contacts')
            ->where('organizer_id', $organizerId)
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $syncStartedAt))
            ->where(fn ($q) => $q->where('orders_count', '>', 0)->orWhere('events_count', '>', 0));

        if ($emails !== null) {
            $query->whereIn('email', $emails);
        }

        $query->update([
            'orders_count' => 0,
            'events_count' => 0,
            'attended_events_count' => 0,
            'total_spent' => 0,
            'first_order_at' => null,
            'last_order_at' => null,
            'last_synced_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('now()'),
        ]);
    }
}
