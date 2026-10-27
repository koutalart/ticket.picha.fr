<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Knowledge;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\EventAssistantFactSource;
use HiEvents\DomainObjects\EventAssistantFactDomainObject;
use HiEvents\DomainObjects\EventAssistantSnapshotDomainObject;
use HiEvents\DomainObjects\Generated\EventAssistantFactDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventAssistantSnapshotDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EventAssistantFactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventAssistantSnapshotRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use Illuminate\Support\Collection;

/**
 * What the chat assistant knows about an event beyond its page: the facts confirmed by the organizer or an admin,
 * the changes noticed on the event over time (with the former values), the SMS announcements already sent for it and
 * the latest publications of the organizer.
 */
class EventAssistantKnowledgeService
{
    public const TIMEZONE = 'Indian/Mayotte';

    private const MAX_FACTS = 40;

    private const MAX_ANNOUNCEMENTS = 5;

    private const MAX_PUBLICATIONS = 5;

    private const PUBLICATION_DAYS = 30;

    private const MAX_TEXT = 400;

    private const SNAPSHOT_FIELDS = [
        'titre' => 'le titre',
        'debut' => 'le début',
        'fin' => 'la fin',
        'lieu' => 'le lieu',
        'artistes' => 'les artistes',
        'billets' => 'les billets et les prix',
        'infos_pratiques' => 'les infos pratiques',
        'description' => 'la description',
    ];

    public function __construct(
        private readonly EventAssistantFactRepositoryInterface $factRepository,
        private readonly EventAssistantSnapshotRepositoryInterface $snapshotRepository,
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
        private readonly SocialPostRepositoryInterface $socialPostRepository,
    ) {}

    public function addFact(
        int $eventId,
        int $organizerId,
        string $answer,
        EventAssistantFactSource $source,
        ?string $subject = null,
        ?int $questionId = null,
        ?Carbon $confirmedAt = null,
    ): EventAssistantFactDomainObject {
        /** @var EventAssistantFactDomainObject $fact */
        $fact = $this->factRepository->create([
            EventAssistantFactDomainObjectAbstract::EVENT_ID => $eventId,
            EventAssistantFactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            EventAssistantFactDomainObjectAbstract::SUBJECT => $subject !== null ? mb_substr(trim($subject), 0, 255) : null,
            EventAssistantFactDomainObjectAbstract::ANSWER => trim($answer),
            EventAssistantFactDomainObjectAbstract::SOURCE => $source->name,
            EventAssistantFactDomainObjectAbstract::LIVE_CHAT_QUESTION_ID => $questionId,
            EventAssistantFactDomainObjectAbstract::CONFIRMED_AT => ($confirmedAt ?? Carbon::now('UTC'))->toDateTimeString(),
        ]);

        return $fact;
    }

    /**
     * @return Collection<int, EventAssistantFactDomainObject>
     */
    public function facts(int $eventId): Collection
    {
        return $this->factRepository->findWhere(
            [EventAssistantFactDomainObjectAbstract::EVENT_ID => $eventId],
            orderAndDirections: [new OrderAndDirection(EventAssistantFactDomainObjectAbstract::ID, 'asc')],
        );
    }

    public function removeFact(int $eventId, int $factId): bool
    {
        return $this->factRepository->deleteWhere([
            EventAssistantFactDomainObjectAbstract::ID => $factId,
            EventAssistantFactDomainObjectAbstract::EVENT_ID => $eventId,
        ]) > 0;
    }

    /**
     * Per event id: confirmed facts, changes and announcements, as shown to the model.
     *
     * @param  array<int, int>  $eventIds
     * @return array<int, array<string, array<int, array<string, string>>>>
     */
    public function forEvents(int $organizerId, array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter($eventIds)));
        if ($eventIds === []) {
            return [];
        }

        $knowledge = [];
        $facts = $this->factRepository->findWhereIn(EventAssistantFactDomainObjectAbstract::EVENT_ID, $eventIds, [
            [EventAssistantFactDomainObjectAbstract::CONFIRMED_AT, 'not null', null],
        ]);

        foreach ($facts->sortBy(fn (EventAssistantFactDomainObject $fact) => $fact->getId()) as $fact) {
            $key = $fact->getSource() === EventAssistantFactSource::CHANGE->name ? 'changements' : 'faits_confirmes';
            $knowledge[$fact->getEventId()][$key][] = array_filter([
                'sujet' => $fact->getSubject(),
                'reponse' => $fact->getAnswer(),
                'date' => $this->localDate($fact->getConfirmedAt()),
            ], fn ($value) => $value !== null && $value !== '');
        }

        foreach ($knowledge as $eventId => $sections) {
            foreach ($sections as $key => $items) {
                $knowledge[$eventId][$key] = array_slice($items, -self::MAX_FACTS);
            }
        }

        $campaigns = $this->campaignRepository->findWhere(
            [
                SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                [SmsCampaignDomainObjectAbstract::EVENT_ID, 'in', $eventIds],
                [SmsCampaignDomainObjectAbstract::STARTED_AT, 'not null', null],
            ],
            orderAndDirections: [new OrderAndDirection(SmsCampaignDomainObjectAbstract::STARTED_AT, 'desc')],
        );

        foreach ($campaigns as $campaign) {
            /** @var SmsCampaignDomainObject $campaign */
            $text = self::announcementText($campaign->getMessage());
            if ($text === null || count($knowledge[$campaign->getEventId()]['annonces'] ?? []) >= self::MAX_ANNOUNCEMENTS) {
                continue;
            }
            $knowledge[$campaign->getEventId()]['annonces'][] = [
                'date' => $this->localDate($campaign->getStartedAt()),
                'texte' => $text,
            ];
        }

        return $knowledge;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function publications(int $organizerId): array
    {
        return $this->socialPostRepository->findWhere(
            [
                SocialPostDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::PUBLISHED->name,
                [SocialPostDomainObjectAbstract::PUBLISHED_AT, '>=', Carbon::now('UTC')->subDays(self::PUBLICATION_DAYS)->toDateTimeString()],
            ],
            orderAndDirections: [new OrderAndDirection(SocialPostDomainObjectAbstract::PUBLISHED_AT, 'desc')],
        )
            ->map(fn (SocialPostDomainObject $post) => [
                'date' => $this->localDate($post->getPublishedAt()),
                'texte' => self::announcementText($post->getCaption()),
            ])
            ->filter(fn (array $post) => $post['texte'] !== null)
            ->take(self::MAX_PUBLICATIONS)
            ->values()
            ->all();
    }

    /**
     * Compares the event as the assistant sees it now with the last snapshot and records every difference as a
     * CHANGE fact, keeping the former value. The first snapshot of an event records nothing.
     *
     * @return int The number of changes recorded
     */
    public function recordChanges(int $organizerId, SmsAssistantEventInfoDTO $event, ?Carbon $now = null): int
    {
        if ($event->id === null) {
            return 0;
        }

        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $current = self::snapshotOf($event);

        /** @var EventAssistantSnapshotDomainObject|null $snapshot */
        $snapshot = $this->snapshotRepository->findFirstWhere([EventAssistantSnapshotDomainObjectAbstract::EVENT_ID => $event->id]);
        if ($snapshot === null) {
            $this->snapshotRepository->create([
                EventAssistantSnapshotDomainObjectAbstract::EVENT_ID => $event->id,
                EventAssistantSnapshotDomainObjectAbstract::SNAPSHOT => $current,
            ]);

            return 0;
        }

        $previous = $snapshot->getSnapshot();
        $previous = is_string($previous) ? (json_decode($previous, true) ?: []) : (array) $previous;

        $changes = self::differences($previous, $current);
        foreach ($changes as $change) {
            $this->addFact($event->id, $organizerId, $change, EventAssistantFactSource::CHANGE, confirmedAt: $now);
        }

        if ($changes !== []) {
            $this->snapshotRepository->updateFromArray($snapshot->getId(), [
                EventAssistantSnapshotDomainObjectAbstract::SNAPSHOT => $current,
            ]);
        }

        return count($changes);
    }

    /**
     * @return array<string, string|null>
     */
    public static function snapshotOf(SmsAssistantEventInfoDTO $event): array
    {
        return [
            'titre' => $event->title,
            'debut' => $event->startsAt,
            'fin' => $event->endsAt,
            'lieu' => $event->venue,
            'artistes' => $event->artists !== [] ? implode(', ', $event->artists) : null,
            'billets' => $event->tickets !== [] ? implode(', ', array_map(
                fn ($ticket) => trim($ticket->name.' '.($ticket->price !== null ? $ticket->price.' '.($ticket->currency ?? '') : '')),
                $event->tickets,
            )) : null,
            'infos_pratiques' => $event->practicalInfo !== [] ? implode(' | ', array_map(
                fn (array $section) => trim(($section['titre'] ?? '').' : '.($section['contenu'] ?? '')),
                $event->practicalInfo,
            )) : null,
            'description' => $event->description,
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, string|null>  $current
     * @return list<string>
     */
    public static function differences(array $previous, array $current): array
    {
        $changes = [];
        foreach (self::SNAPSHOT_FIELDS as $field => $label) {
            $before = isset($previous[$field]) && is_string($previous[$field]) && trim($previous[$field]) !== '' ? trim($previous[$field]) : null;
            $after = isset($current[$field]) && trim((string) $current[$field]) !== '' ? trim((string) $current[$field]) : null;
            if ($before === $after) {
                continue;
            }

            $changes[] = match (true) {
                $field === 'description' => 'La description a changé. Ancienne version : '.self::shorten($before ?? 'vide'),
                $before === null => sprintf('Ajout pour %s : %s', $label, self::shorten($after)),
                $after === null => sprintf('Retiré pour %s, avant : %s', $label, self::shorten($before)),
                default => sprintf('Changement pour %s, avant : %s. Maintenant : %s', $label, self::shorten($before), self::shorten($after)),
            };
        }

        return $changes;
    }

    private static function announcementText(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\{[a-z_]+\}/iu', '', (string) $text));
        if ($text === '') {
            return null;
        }

        return self::shorten(SmsAssistantReplyGenerator::cleanText($text));
    }

    private static function shorten(?string $text): string
    {
        $text = (string) $text;

        return mb_strlen($text) > self::MAX_TEXT ? rtrim(mb_substr($text, 0, self::MAX_TEXT - 3)).'...' : $text;
    }

    private function localDate(?string $date): ?string
    {
        return $date === null ? null : Carbon::parse($date, 'UTC')->setTimezone(self::TIMEZONE)->locale('fr')->isoFormat('D MMMM YYYY');
    }
}
