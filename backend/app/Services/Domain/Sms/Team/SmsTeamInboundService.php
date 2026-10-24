<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Team;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerAssistantService;
use HiEvents\Services\Domain\Sms\Team\DTO\SmsTeamRouteDTO;
use Illuminate\Support\Collection;

/**
 * Routes an SMS received on a line from a member of an organizer team. It goes to the organizer of that team, never to
 * the customer assistant, and never moves the « last who wrote » attribution of a shared line.
 *
 * When the number is in the team of several organizers of the line, the SMS goes to the organizer whose team
 * conversation with the number had the latest message: the person most likely answers the last SMS it got there.
 * Without any team conversation yet, it goes to the organizer the number wrote to last, then to the team the number
 * joined last. Platform supervisors belong to no team and are never routed here.
 */
class SmsTeamInboundService
{
    public function __construct(
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly PlatformSupervisorService $supervisorService,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerAssistantService $organizerAssistantService,
    ) {}

    public function isSupervisor(string $phone): bool
    {
        return $this->supervisorService->isSupervisor($phone);
    }

    /**
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $candidates  The organizers an SMS received on the line may belong to
     */
    public function route(Collection $candidates, string $contact): ?SmsTeamRouteDTO
    {
        $phone = SmsPhoneNormalizer::normalize($contact);
        if ($phone === null || $candidates->isEmpty() || $this->supervisorService->isSupervisor($phone)) {
            return null;
        }

        $organizerIds = $candidates
            ->map(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId())
            ->unique()
            ->values()
            ->all();

        $contacts = $this->teamContactService->findForPhone($organizerIds, $phone);
        if ($contacts->isEmpty()) {
            return null;
        }

        $contact = $contacts->count() === 1 ? $contacts->first() : $this->pick($contacts, $phone);
        $settings = $candidates->first(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId() === $contact->getOrganizerId());

        return $settings === null ? null : new SmsTeamRouteDTO($settings, $contact);
    }

    /**
     * Marks the conversation « Équipe » and lets the organizer assistant answer when the sender runs the organizer.
     */
    public function afterInbound(SmsTeamRouteDTO $route, SmsMessageDomainObject $message): void
    {
        $this->teamContactService->markConversation($route->contact->getOrganizerId(), $route->contact->getPhone(), true, $route->contact->getName());
        $this->organizerAssistantService->queueIfEligible($route->settings, $route->contact, $message);
    }

    public function organizerAssistantActiveFor(OrganizerSmsSettingDomainObject $settings): bool
    {
        return $this->organizerAssistantService->isActiveFor($settings);
    }

    /**
     * @param  Collection<int, OrganizerTeamContactDomainObject>  $contacts
     */
    private function pick(Collection $contacts, string $phone): OrganizerTeamContactDomainObject
    {
        $organizerIds = $contacts->map(fn (OrganizerTeamContactDomainObject $contact) => $contact->getOrganizerId())->values()->all();

        $organizerId = $this->conversationRepository->findLatestTeamConversationOrganizerId($organizerIds, $phone)
            ?? $this->messageRepository->findLastInboundOrganizerId($organizerIds, $phone);

        return $contacts->first(fn (OrganizerTeamContactDomainObject $contact) => $contact->getOrganizerId() === $organizerId)
            ?? $contacts->sortByDesc(fn (OrganizerTeamContactDomainObject $contact) => $contact->getId())->first();
    }
}
