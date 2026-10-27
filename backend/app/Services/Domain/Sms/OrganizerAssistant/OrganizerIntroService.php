<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\OrganizerAssistant;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use HiEvents\Services\Domain\Sms\Team\DTO\SmsBulkSendResultDTO;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Domain\Sms\Team\SmsDirectSendService;

/**
 * The first SMS of Anicka to the people who run an organizer. The text is checked by a human before it leaves.
 */
class OrganizerIntroService
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly SmsDirectSendService $directSendService,
    ) {}

    public function defaultText(int $organizerId): string
    {
        $organizer = $this->organizerRepository->findById($organizerId);

        return __('Hello, I am Anicka, your PICHA Ticket contact for :organizer. Write to me here about your registrations, your questions or your contact list for an SMS campaign. I will answer you very quickly.', [
            'organizer' => $organizer->getName(),
        ], 'fr')."\n".OrganizerAssistantReplyGenerator::SIGNATURE;
    }

    /**
     * @return array<int, string>
     */
    public function recipients(int $organizerId): array
    {
        return $this->teamContactService->list($organizerId, OrganizerTeamContactRole::ORGANIZER)
            ->map(fn (OrganizerTeamContactDomainObject $contact) => $contact->getPhone())
            ->values()
            ->all();
    }

    /**
     * @throws SmsGatewayNotConfiguredException
     */
    public function send(int $organizerId, string $text): SmsBulkSendResultDTO
    {
        return $this->directSendService->sendToMany(
            $organizerId,
            $this->recipients($organizerId),
            SmsAssistantReplyGenerator::cleanText($text),
            SmsMessageAuthorType::AGENT,
            awaitsReply: true,
        );
    }
}
