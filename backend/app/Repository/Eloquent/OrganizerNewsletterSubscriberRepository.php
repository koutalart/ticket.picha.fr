<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\NewsletterSubscriberSource;
use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\OrganizerNewsletterSubscriber;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\NewsletterSubscriberSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * A subscriber is active while it has no unsubscribe newer than its consent:
 * unsubscribed_at IS NULL OR unsubscribed_at < consented_at.
 * Unsubscribing keeps the row (email + date) so that an older consent can never re-add the address;
 * only a new explicit consent, given after the unsubscribe, makes it active again.
 * An unsubscribe recorded on the CRM contact (contacts.email_unsubscribed_at) is honoured the same way.
 *
 * @extends BaseRepository<OrganizerNewsletterSubscriberDomainObject>
 */
class OrganizerNewsletterSubscriberRepository extends BaseRepository implements OrganizerNewsletterSubscriberRepositoryInterface
{
    private const ACTIVE_SQL = '(organizer_newsletter_subscribers.unsubscribed_at IS NULL OR organizer_newsletter_subscribers.unsubscribed_at < organizer_newsletter_subscribers.consented_at)';

    private const CONTACT_UNSUBSCRIBED_AFTER_CONSENT_SQL = 'NOT EXISTS (
        SELECT 1 FROM contacts ct
        WHERE ct.organizer_id = organizer_newsletter_subscribers.organizer_id
          AND ct.email = organizer_newsletter_subscribers.email
          AND ct.email_unsubscribed_at IS NOT NULL
          AND ct.email_unsubscribed_at >= organizer_newsletter_subscribers.consented_at
    )';

    protected function getModel(): string
    {
        return OrganizerNewsletterSubscriber::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerNewsletterSubscriberDomainObject::class;
    }

    public function createIfNotSubscribed(array $attributes): bool
    {
        $now = now();
        $row = [
            'source' => NewsletterSubscriberSource::FOOTER->name,
            ...$attributes,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $columns = array_keys($row);
        $updates = collect($columns)
            ->reject(fn (string $column) => in_array($column, ['organizer_id', 'email', 'order_id', 'created_at'], true))
            ->map(fn (string $column) => "$column = EXCLUDED.$column")
            ->implode(', ');

        $sql = sprintf(
            'INSERT INTO organizer_newsletter_subscribers (%s) VALUES (%s)
            ON CONFLICT (organizer_id, email) DO UPDATE SET %s, order_id = %s
            WHERE organizer_newsletter_subscribers.unsubscribed_at IS NOT NULL
              AND organizer_newsletter_subscribers.unsubscribed_at >= organizer_newsletter_subscribers.consented_at
              AND organizer_newsletter_subscribers.unsubscribed_at < EXCLUDED.consented_at',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
            $updates,
            in_array('order_id', $columns, true) ? 'EXCLUDED.order_id' : 'NULL',
        );

        return DB::affectingStatement($sql, array_map(
            fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            array_values($row),
        )) > 0;
    }

    public function unsubscribe(int $organizerId, int $subscriberId): bool
    {
        return OrganizerNewsletterSubscriber::query()
            ->where('id', $subscriberId)
            ->where('organizer_id', $organizerId)
            ->whereRaw(self::ACTIVE_SQL)
            ->update(['unsubscribed_at' => now(), 'updated_at' => now()]) > 0;
    }

    public function subscribeConsentingBuyers(int $organizerId, string $consentText, ?int $orderId = null, bool $dryRun = false): int
    {
        $eligible = <<<'SQL'
WITH candidates AS (
    SELECT DISTINCT ON (lower(trim(o.email)))
           lower(trim(o.email)) AS email, o.id AS order_id, o.event_id, o.opted_into_marketing_at AS consented_at
    FROM orders o
    JOIN events e ON e.id = o.event_id
    WHERE e.organizer_id = ?
      AND (?::bigint IS NULL OR o.id = ?::bigint)
      AND o.deleted_at IS NULL
      AND o.status IN (?, ?)
      AND o.opted_into_marketing_at IS NOT NULL
      AND o.email IS NOT NULL AND trim(o.email) <> ''
      AND lower(trim(o.email)) NOT LIKE '%.invalid'
    ORDER BY lower(trim(o.email)), o.opted_into_marketing_at DESC, o.id DESC
),
eligible AS (
    SELECT c.*
    FROM candidates c
    LEFT JOIN organizer_newsletter_subscribers s ON s.organizer_id = ? AND s.email = c.email
    WHERE (s.id IS NULL OR (s.unsubscribed_at IS NOT NULL AND s.unsubscribed_at >= s.consented_at AND s.unsubscribed_at < c.consented_at))
      AND NOT EXISTS (
          SELECT 1 FROM contacts ct
          WHERE ct.organizer_id = ? AND ct.email = c.email
            AND ct.email_unsubscribed_at IS NOT NULL AND ct.email_unsubscribed_at >= c.consented_at
      )
)
SQL;

        $bindings = [
            $organizerId,
            $orderId,
            $orderId,
            OrderStatus::COMPLETED->name,
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            $organizerId,
            $organizerId,
        ];

        if ($dryRun) {
            return (int) DB::selectOne($eligible.' SELECT count(*) AS total FROM eligible', $bindings)->total;
        }

        $sql = $eligible.<<<'SQL'
INSERT INTO organizer_newsletter_subscribers (
    organizer_id, event_id, order_id, email, source, consent_text, consented_at, ip, user_agent, created_at, updated_at
)
SELECT ?, event_id, order_id, email, ?, ?, consented_at, NULL, NULL, now(), now()
FROM eligible
ON CONFLICT (organizer_id, email) DO UPDATE SET
    event_id = EXCLUDED.event_id,
    order_id = EXCLUDED.order_id,
    source = EXCLUDED.source,
    consent_text = EXCLUDED.consent_text,
    consented_at = EXCLUDED.consented_at,
    ip = NULL,
    user_agent = NULL,
    updated_at = now()
WHERE organizer_newsletter_subscribers.unsubscribed_at IS NOT NULL
  AND organizer_newsletter_subscribers.unsubscribed_at >= organizer_newsletter_subscribers.consented_at
  AND organizer_newsletter_subscribers.unsubscribed_at < EXCLUDED.consented_at
SQL;

        return DB::affectingStatement($sql, [
            ...$bindings,
            $organizerId,
            NewsletterSubscriberSource::REGISTRATION->name,
            $consentText,
        ]);
    }

    public function searchForOrganizer(int $organizerId, NewsletterSubscriberSearchDTO $search): LengthAwarePaginator
    {
        $query = OrganizerNewsletterSubscriber::query()
            ->select('organizer_newsletter_subscribers.*', 'events.title as event_title')
            ->leftJoin('events', 'events.id', '=', 'organizer_newsletter_subscribers.event_id')
            ->where('organizer_newsletter_subscribers.organizer_id', $organizerId)
            ->whereRaw(self::ACTIVE_SQL)
            ->whereRaw(self::CONTACT_UNSUBSCRIBED_AFTER_CONSENT_SQL);

        $term = trim((string) $search->query);
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

            $query->where(function ($inner) use ($like) {
                $inner->where('organizer_newsletter_subscribers.email', 'ilike', $like)
                    ->orWhere('events.title', 'ilike', $like);
            });
        }

        $results = $query
            ->orderByDesc('organizer_newsletter_subscribers.consented_at')
            ->orderByDesc('organizer_newsletter_subscribers.id')
            ->paginate(perPage: $search->perPage, page: $search->page);

        return $this->handleResults($results);
    }
}
