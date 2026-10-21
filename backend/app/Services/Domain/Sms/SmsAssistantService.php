<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsAssistantOutcome;
use HiEvents\DomainObjects\Enums\SmsAssistantTrigger;
use HiEvents\DomainObjects\Enums\SmsDraftSource;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Helper\SmsKeywordDetector;
use HiEvents\Helper\SmsReactionDetector;
use HiEvents\Jobs\Sms\GenerateSmsDraftJob;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantRequestDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantResultDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantThreadMessageDTO;
use Illuminate\Support\Collection;

class SmsAssistantService
{
    private const THREAD_LENGTH = 20;

    public function __construct(
        private readonly SmsAssistantReplyGenerator $generator,
        private readonly SmsAssistantEventCatalogService $catalogService,
        private readonly SmsConversationService $conversationService,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SmsPersonalLinkService $personalLinkService,
    ) {}

    public function isAvailable(): bool
    {
        return $this->generator->isConfigured();
    }

    public function isActiveFor(?OrganizerSmsSettingDomainObject $settings): bool
    {
        return $settings !== null && $settings->getAssistantEnabled() && $this->isAvailable();
    }

    public static function needsReply(SmsMessageDomainObject $message): bool
    {
        return $message->getDirection() === SmsMessageDirection::INBOUND->name
            && SmsKeywordDetector::detect($message->getContent()) === null
            && ! SmsReactionDetector::isReaction($message->getContent());
    }

    public function queueDraftIfEligible(OrganizerSmsSettingDomainObject $settings, SmsMessageDomainObject $message): void
    {
        if (! $this->isActiveFor($settings) || ! self::needsReply($message)) {
            return;
        }

        GenerateSmsDraftJob::dispatch($message->getSmsConversationId(), $message->getId());
    }

    /**
     * Drafts a reply for a new customer SMS. Does nothing when the SMS is no longer the last message of the conversation,
     * when the team is already handling the conversation, or when the customer replied STOP.
     *
     * @throws SmsAssistantUnavailableException
     * @throws SmsAssistantLimitReachedException
     * @throws SmsAssistantFailedException
     */
    public function draftForInboundMessage(int $conversationId, int $messageId): ?SmsAssistantResultDTO
    {
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $conversationId,
        ]);
        if ($conversation === null || $conversation->getStatus() === SmsConversationStatus::NEEDS_HUMAN->name) {
            return null;
        }

        $settings = $this->findSettings($conversation->getOrganizerId());
        if (! $this->isActiveFor($settings)) {
            return null;
        }

        $messages = $this->messageRepository->findLatestForConversation($conversationId, self::THREAD_LENGTH);
        $last = $messages->last();
        if ($last === null || $last->getId() !== $messageId || ! self::needsReply($last)) {
            return null;
        }

        if ($this->conversationService->isOptedOut($conversation->getOrganizerId(), $conversation->getPhone())) {
            return null;
        }

        return $this->draft($conversation, $settings, $messages, SmsAssistantTrigger::INBOUND);
    }

    /**
     * Drafts a new reply on request of the team, whatever the state of the conversation.
     *
     * @throws SmsAssistantUnavailableException
     * @throws SmsAssistantLimitReachedException
     * @throws SmsAssistantFailedException
     * @throws SmsRecipientOptedOutException
     */
    public function regenerate(SmsConversationDomainObject $conversation): SmsConversationDomainObject
    {
        $settings = $this->findSettings($conversation->getOrganizerId());
        if (! $this->isActiveFor($settings)) {
            throw new SmsAssistantUnavailableException(__('Turn on the SMS assistant in Settings to get suggested replies.'));
        }

        if ($this->conversationService->isOptedOut($conversation->getOrganizerId(), $conversation->getPhone())) {
            throw new SmsRecipientOptedOutException(__('This number replied STOP and can no longer receive SMS from you'));
        }

        $messages = $this->messageRepository->findLatestForConversation($conversation->getId(), self::THREAD_LENGTH);
        if ($messages->first(fn (SmsMessageDomainObject $message) => self::needsReply($message)) === null) {
            throw new SmsAssistantFailedException(__('There is no customer message to reply to.'));
        }

        $this->draft($conversation, $settings, $messages, SmsAssistantTrigger::REGENERATE);

        return $this->conversationRepository->findById($conversation->getId());
    }

    /**
     * Shows what the assistant would answer to a sample SMS, with the given instructions.
     *
     * @throws SmsAssistantUnavailableException
     * @throws SmsAssistantLimitReachedException
     * @throws SmsAssistantFailedException
     */
    public function preview(int $organizerId, string $customerMessage, ?string $instructions): SmsAssistantResultDTO
    {
        $organizer = $this->findOrganizer($organizerId);

        return $this->generator->generate(new SmsAssistantRequestDTO(
            organizerId: $organizerId,
            conversationId: null,
            trigger: SmsAssistantTrigger::PREVIEW,
            organizerName: $organizer->getName(),
            timezone: $organizer->getTimezone(),
            instructions: $instructions,
            contactName: null,
            thread: collect([new SmsAssistantThreadMessageDTO(
                author: SmsMessageAuthorType::CONTACT,
                content: $customerMessage,
                sentAt: now()->toDateTimeString(),
            )]),
            customerOrders: collect(),
            events: $this->catalogService->getUpcomingEvents($organizerId),
        ));
    }

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     *
     * @throws SmsAssistantUnavailableException
     * @throws SmsAssistantLimitReachedException
     * @throws SmsAssistantFailedException
     */
    private function draft(
        SmsConversationDomainObject $conversation,
        OrganizerSmsSettingDomainObject $settings,
        Collection $messages,
        SmsAssistantTrigger $trigger,
    ): SmsAssistantResultDTO {
        $organizerId = $conversation->getOrganizerId();
        $organizer = $this->findOrganizer($organizerId);

        $result = $this->generator->generate(new SmsAssistantRequestDTO(
            organizerId: $organizerId,
            conversationId: $conversation->getId(),
            trigger: $trigger,
            organizerName: $organizer->getName(),
            timezone: $organizer->getTimezone(),
            instructions: $settings->getAssistantInstructions(),
            contactName: $conversation->getContactName(),
            thread: $messages->map(fn (SmsMessageDomainObject $message) => new SmsAssistantThreadMessageDTO(
                author: SmsMessageAuthorType::fromName($message->getAuthorType()),
                content: $message->getContent(),
                sentAt: $message->getProviderTimestamp() ?? $message->getCreatedAt(),
            ))->values(),
            customerOrders: $this->conversationRepository->findOrdersByPhone($organizerId, $conversation->getPhone()),
            events: $this->catalogService->getUpcomingEvents($organizerId),
            personalLink: $this->personalLinkService->findForContact($organizerId, $conversation->getPhone(), $messages),
        ));

        $this->conversationRepository->updateFromArray($conversation->getId(), $result->outcome === SmsAssistantOutcome::DRAFT
            ? [
                SmsConversationDomainObjectAbstract::DRAFT_REPLY => $result->reply,
                SmsConversationDomainObjectAbstract::DRAFT_SOURCE => SmsDraftSource::AGENT->name,
                SmsConversationDomainObjectAbstract::DRAFT_GENERATED_AT => now()->toDateTimeString(),
            ]
            : [
                SmsConversationDomainObjectAbstract::STATUS => SmsConversationStatus::NEEDS_HUMAN->name,
                SmsConversationDomainObjectAbstract::NEEDS_HUMAN_REASON => $result->reason,
                SmsConversationDomainObjectAbstract::DRAFT_REPLY => null,
                SmsConversationDomainObjectAbstract::DRAFT_SOURCE => null,
                SmsConversationDomainObjectAbstract::DRAFT_GENERATED_AT => null,
            ]);

        return $result;
    }

    private function findSettings(int $organizerId): ?OrganizerSmsSettingDomainObject
    {
        return $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);
    }

    private function findOrganizer(int $organizerId): OrganizerDomainObject
    {
        return $this->organizerRepository->findById($organizerId);
    }
}
