<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\ContactConsentSource;
use HiEvents\DomainObjects\Enums\ContactSource;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\Contact;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Support\Facades\DB;

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
