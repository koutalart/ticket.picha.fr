<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;
use HiEvents\Models\OrganizerNewsletterSubscriber;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\NewsletterSubscriberSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<OrganizerNewsletterSubscriberDomainObject>
 */
class OrganizerNewsletterSubscriberRepository extends BaseRepository implements OrganizerNewsletterSubscriberRepositoryInterface
{
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

        return OrganizerNewsletterSubscriber::query()->insertOrIgnore([
            ...$attributes,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    public function searchForOrganizer(int $organizerId, NewsletterSubscriberSearchDTO $search): LengthAwarePaginator
    {
        $query = OrganizerNewsletterSubscriber::query()
            ->select('organizer_newsletter_subscribers.*', 'events.title as event_title')
            ->leftJoin('events', 'events.id', '=', 'organizer_newsletter_subscribers.event_id')
            ->where('organizer_newsletter_subscribers.organizer_id', $organizerId);

        $term = trim((string)$search->query);
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

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
