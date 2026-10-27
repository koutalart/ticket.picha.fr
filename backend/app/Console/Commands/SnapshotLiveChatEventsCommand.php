<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\Knowledge\EventAssistantKnowledgeService;
use HiEvents\Services\Domain\Sms\SmsAssistantEventCatalogService;
use Illuminate\Console\Command;

class SnapshotLiveChatEventsCommand extends Command
{
    protected $signature = 'live-chat:snapshot-events';

    protected $description = 'Record the changes of the upcoming events (programme, artists, times, venue, prices, description) for the chat assistant';

    public function handle(
        EventRepositoryInterface $eventRepository,
        SmsAssistantEventCatalogService $catalogService,
        EventAssistantKnowledgeService $knowledgeService,
    ): int {
        $organizerIds = $eventRepository->findWhere([
            EventDomainObjectAbstract::STATUS => EventStatus::LIVE->name,
            [EventDomainObjectAbstract::START_DATE, '>=', now()->subDays(2)->toDateTimeString()],
        ])
            ->map(fn (EventDomainObject $event) => $event->getOrganizerId())
            ->unique()
            ->values();

        $changes = 0;
        foreach ($organizerIds as $organizerId) {
            foreach ($catalogService->getUpcomingEvents($organizerId, withDetails: true) as $event) {
                $changes += $knowledgeService->recordChanges($organizerId, $event);
            }
        }

        if ($changes > 0) {
            $this->info(sprintf('Changes recorded: %d', $changes));
        }

        return self::SUCCESS;
    }
}
