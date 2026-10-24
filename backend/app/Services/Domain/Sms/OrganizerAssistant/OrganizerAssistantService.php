<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\OrganizerAssistant;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerTeamContactDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\Exceptions\EventDailyReportException;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Jobs\Sms\GenerateOrganizerAssistantReplyJob;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerTeamContactRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantThreadMessageDTO;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO\OrganizerAssistantRequestDTO;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO\OrganizerAssistantResultDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantEventCatalogService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisionAlertService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Answers right away, in its conversation, the SMS of a team contact who runs the organizer (role ORGANIZER).
 * Contacts in copy never get an automatic answer. When a human must act, only a holding reply leaves, the conversation
 * waits for a human, and the platform supervision is alerted at once.
 */
class OrganizerAssistantService
{
    private const THREAD_LENGTH = 20;

    public function __construct(
        private readonly OrganizerAssistantReplyGenerator $generator,
        private readonly SmsAssistantEventCatalogService $catalogService,
        private readonly EventDailyReportService $reportService,
        private readonly SmsConversationService $conversationService,
        private readonly SmsSendService $sendService,
        private readonly PlatformSupervisionAlertService $supervisionAlertService,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly OrganizerTeamContactRepositoryInterface $teamContactRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function isActiveFor(?OrganizerSmsSettingDomainObject $settings): bool
    {
        return $settings !== null && $settings->getOrganizerAssistantEnabled() && $this->generator->isConfigured();
    }

    public function queueIfEligible(
        OrganizerSmsSettingDomainObject $settings,
        OrganizerTeamContactDomainObject $contact,
        SmsMessageDomainObject $message,
    ): bool {
        if ($contact->getRole() !== OrganizerTeamContactRole::ORGANIZER->name
            || ! $this->isActiveFor($settings)
            || ! SmsAssistantService::needsReply($message)) {
            return false;
        }

        $this->dispatcher->dispatch(new GenerateOrganizerAssistantReplyJob($message->getSmsConversationId(), $message->getId()));

        return true;
    }

    /**
     * When the model fails, the holding reply leaves and a human is alerted, so the organizer is never left unanswered.
     *
     * @throws SmsAssistantUnavailableException
     */
    public function replyToInbound(int $conversationId, int $messageId): ?OrganizerAssistantResultDTO
    {
        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([SmsConversationDomainObjectAbstract::ID => $conversationId]);
        if ($conversation === null || ! $conversation->getIsTeam()) {
            return null;
        }

        $organizerId = $conversation->getOrganizerId();
        $settings = $this->settingsRepository->findFirstWhere([OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId]);
        if (! $this->isActiveFor($settings)) {
            return null;
        }

        /** @var OrganizerTeamContactDomainObject|null $contact */
        $contact = $this->teamContactRepository->findFirstWhere([
            OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            OrganizerTeamContactDomainObjectAbstract::PHONE => $conversation->getPhone(),
        ]);
        if ($contact === null || $contact->getRole() !== OrganizerTeamContactRole::ORGANIZER->name) {
            return null;
        }

        $messages = $this->messageRepository->findLatestForConversation($conversationId, self::THREAD_LENGTH);
        $last = $messages->last();
        if ($last === null || $last->getId() !== $messageId || ! SmsAssistantService::needsReply($last)) {
            return null;
        }

        if ($this->conversationService->isOptedOut($organizerId, $conversation->getPhone())
            || $this->messageRepository->countWhere([SmsMessageDomainObjectAbstract::AUTO_REPLY_TO_MESSAGE_ID => $messageId]) > 0) {
            return null;
        }

        $organizer = $this->organizerRepository->findById($organizerId);
        try {
            $result = $this->generator->generate($this->request($organizer, $settings, $conversation, $contact, $messages));
        } catch (SmsAssistantFailedException) {
            $result = new OrganizerAssistantResultDTO(
                reply: OrganizerAssistantReplyGenerator::holdingReply(),
                needsHuman: true,
                summary: __('Anicka could not prepare an answer, a human must reply.'),
            );
        }

        $this->send($organizerId, $conversation, $messageId, $result);

        if ($result->needsHuman) {
            $this->conversationRepository->updateFromArray($conversation->getId(), [
                SmsConversationDomainObjectAbstract::STATUS => SmsConversationStatus::NEEDS_HUMAN->name,
                SmsConversationDomainObjectAbstract::NEEDS_HUMAN_REASON => mb_substr((string) ($result->summary ?? __('The organizer is waiting for an answer')), 0, 255),
            ]);
            $this->alert($organizer, $conversation, $contact, $last, $result);
        }

        return $result;
    }

    private function send(int $organizerId, SmsConversationDomainObject $conversation, int $messageId, OrganizerAssistantResultDTO $result): void
    {
        try {
            $this->sendService->send(new SendSmsDTO(
                organizerId: $organizerId,
                phone: $conversation->getPhone(),
                content: $result->reply,
                authorType: SmsMessageAuthorType::AGENT,
                autoReplyToMessageId: $messageId,
                autoReplyType: $result->needsHuman ? SmsAutoReplyType::HOLDING : SmsAutoReplyType::ANSWER,
                keepDraft: true,
                replyInConversation: true,
            ));
        } catch (SmsRecipientOptedOutException|SmsGatewayNotConfiguredException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
            $this->logger->warning('Organizer assistant: reply not sent', [
                'sms_conversation_id' => $conversation->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function alert(
        OrganizerDomainObject $organizer,
        SmsConversationDomainObject $conversation,
        OrganizerTeamContactDomainObject $contact,
        SmsMessageDomainObject $message,
        OrganizerAssistantResultDTO $result,
    ): void {
        $url = PlatformSupervisionAlertService::conversationUrl($organizer->getId(), $conversation->getId());
        $summary = $result->summary ?? mb_substr($message->getContent(), 0, 200);
        $who = $contact->getName() ?: $contact->getPhone();

        $this->supervisionAlertService->alert(
            organizerId: $organizer->getId(),
            sms: __("Anicka: :organizer needs a human answer (conversation :id).\n:summary\n:url", [
                'organizer' => $organizer->getName(),
                'id' => $conversation->getId(),
                'summary' => $summary,
                'url' => $url,
            ], 'fr'),
            subject: __('Anicka: :organizer needs a human answer', ['organizer' => $organizer->getName()]),
            intro: __(':name wrote to Anicka and is waiting for an action that only a human can do. A holding reply was sent.', ['name' => $who]),
            details: [
                __('Organizer') => $organizer->getName().' (#'.$organizer->getId().')',
                __('Conversation') => (string) $conversation->getId(),
                __('From') => $who.' ('.$contact->getPhone().')',
                __('Summary') => $summary,
                __('Message') => $message->getContent(),
            ],
            url: $url,
        );
    }

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     */
    private function request(
        OrganizerDomainObject $organizer,
        OrganizerSmsSettingDomainObject $settings,
        SmsConversationDomainObject $conversation,
        OrganizerTeamContactDomainObject $contact,
        Collection $messages,
    ): OrganizerAssistantRequestDTO {
        $events = $this->catalogService->getUpcomingEvents($organizer->getId());

        return new OrganizerAssistantRequestDTO(
            organizerId: $organizer->getId(),
            conversationId: $conversation->getId(),
            organizerName: $organizer->getName(),
            timezone: $organizer->getTimezone() ?: EventDailyReportService::DEFAULT_TIMEZONE,
            instructions: $settings->getOrganizerAssistantInstructions(),
            contactName: $contact->getName(),
            thread: $messages->map(fn (SmsMessageDomainObject $message) => new SmsAssistantThreadMessageDTO(
                author: SmsMessageAuthorType::fromName($message->getAuthorType()),
                content: $message->getContent(),
                sentAt: $message->getProviderTimestamp() ?? $message->getCreatedAt(),
            ))->values(),
            events: $events,
            registrations: $this->registrations($events),
        );
    }

    /**
     * @param  Collection<int, SmsAssistantEventInfoDTO>  $events
     * @return array<int, array<string, mixed>>
     */
    private function registrations(Collection $events): array
    {
        $figures = [];
        foreach ($events as $event) {
            if ($event->id === null) {
                continue;
            }

            try {
                $report = $this->reportService->build($event->id);
            } catch (EventDailyReportException) {
                continue;
            }

            $figures[] = [
                'evenement' => $report->eventTitle,
                'inscrits_payes' => $report->stats->totalRegistrations,
                'inscrits_aujourdhui' => $report->stats->todayRegistrations,
                'recettes_inscriptions' => EventDailyReportService::formatAmount($report->stats->revenue, $report->currency),
                'paniers_en_cours' => $report->stats->openCarts,
            ];
        }

        return $figures;
    }
}
