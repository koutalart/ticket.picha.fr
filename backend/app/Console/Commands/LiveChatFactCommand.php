<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\EventAssistantFactSource;
use HiEvents\DomainObjects\EventAssistantFactDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\Knowledge\EventAssistantKnowledgeService;
use Illuminate\Console\Command;

class LiveChatFactCommand extends Command
{
    protected $signature = 'live-chat:fact
        {event : Event id}
        {--add= : Fact to add, confirmed now (source ADMIN)}
        {--subject= : Optional subject of the fact added}
        {--list : List the facts of the event}
        {--remove= : Id of a fact of the event to remove}';

    protected $description = 'Add, list or remove the confirmed facts the chat assistant uses for an event';

    public function handle(EventRepositoryInterface $eventRepository, EventAssistantKnowledgeService $knowledgeService): int
    {
        /** @var EventDomainObject|null $event */
        $event = $eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => (int) $this->argument('event')]);
        if ($event === null) {
            $this->error('Event not found.');

            return self::FAILURE;
        }

        $add = trim((string) $this->option('add'));
        $remove = $this->option('remove');

        if ($add === '' && $remove === null && ! $this->option('list')) {
            $this->error('Use --add="...", --list or --remove=ID.');

            return self::FAILURE;
        }

        if ($add !== '') {
            $fact = $knowledgeService->addFact(
                eventId: $event->getId(),
                organizerId: $event->getOrganizerId(),
                answer: $add,
                source: EventAssistantFactSource::ADMIN,
                subject: trim((string) $this->option('subject')) ?: null,
            );
            $this->info(sprintf('Fact #%d added to event %d (%s).', $fact->getId(), $event->getId(), $event->getTitle()));
        }

        if ($remove !== null) {
            if (! $knowledgeService->removeFact($event->getId(), (int) $remove)) {
                $this->error(sprintf('Fact #%d not found for event %d.', (int) $remove, $event->getId()));

                return self::FAILURE;
            }
            $this->info(sprintf('Fact #%d removed.', (int) $remove));
        }

        if ($this->option('list')) {
            $this->table(
                ['id', 'source', 'confirmed_at', 'subject', 'fact'],
                $knowledgeService->facts($event->getId())->map(fn (EventAssistantFactDomainObject $fact) => [
                    $fact->getId(),
                    $fact->getSource(),
                    $fact->getConfirmedAt(),
                    mb_substr((string) $fact->getSubject(), 0, 60),
                    mb_substr($fact->getAnswer(), 0, 120),
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
