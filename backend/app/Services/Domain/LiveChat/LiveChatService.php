<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\Status\LiveChatSessionStatus;
use HiEvents\Exceptions\LiveChatInvalidContactException;
use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Helper\SmsSensitiveTopicDetector;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatConfigDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatReplyDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatRequestDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatTurnDTO;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundProtectionService;
use HiEvents\Services\Domain\Sms\SmsAssistantEventCatalogService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

class LiveChatService
{
    public const MAX_MESSAGE_LENGTH = 800;

    public const DEFAULT_RELAY_LABEL = "l'organisateur";

    private const THREAD_LENGTH = 40;

    private const TRANSCRIPT_LENGTH = 80;

    private const RATE_WINDOW_SECONDS = 3600;

    private const HOLDING_REPLIES_VOUS = [
        "Je transmets votre question à l'organisateur, il va regarder ça.",
        "Bonne question, je la fais suivre à l'organisateur pour être sûre de bien vous répondre.",
        "Je préfère laisser l'organisateur vous répondre là-dessus, je lui transmets.",
    ];

    private const HOLDING_REPLIES_TU = [
        "Je transmets ta question à l'organisateur, il va regarder ça.",
        "Bonne question, je la fais suivre à l'organisateur pour être sûre de bien te répondre.",
        "Je préfère laisser l'organisateur te répondre là-dessus, je lui transmets.",
    ];

    public function __construct(
        private readonly LiveChatReplyGenerator $generator,
        private readonly LiveChatHandoffService $handoffService,
        private readonly LiveChatRefundProtectionService $refundProtectionService,
        private readonly SmsAssistantEventCatalogService $catalogService,
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly LiveChatMessageRepositoryInterface $messageRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
        private readonly RateLimiter $rateLimiter,
        private readonly Repository $config,
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function getConfig(int $organizerId): LiveChatConfigDTO
    {
        $settings = $this->findEnabledSettings($organizerId);
        $organizer = $settings !== null ? $this->organizerRepository->findFirstWhere(['id' => $organizerId]) : null;

        if ($settings === null || $organizer === null) {
            return new LiveChatConfigDTO(enabled: false);
        }

        return new LiveChatConfigDTO(
            enabled: true,
            organizerName: $organizer->getName(),
            relayLine: self::relayLine($settings->getLiveChatRelayLabel()),
            maxMessageLength: self::MAX_MESSAGE_LENGTH,
        );
    }

    public static function relayLine(?string $label): string
    {
        $label = LiveChatReplyGenerator::cleanText(trim((string) $label));

        return sprintf('Réponse immédiate, %s peut prendre le relais', $label !== '' ? $label : self::DEFAULT_RELAY_LABEL);
    }

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatLimitReachedException
     */
    public function start(int $organizerId, ?int $eventId, string $ip): LiveChatTurnDTO
    {
        $settings = $this->findEnabledSettings($organizerId);
        if ($settings === null) {
            throw new LiveChatUnavailableException(__('The chat is not available.'));
        }

        $ipHash = $this->ipHash($ip);
        $this->hit(
            'live-chat-sessions:'.$ipHash,
            (int) $this->config->get('services.live_chat.max_sessions_per_ip_per_hour', 5),
            LiveChatLimitReachedException::NEW_SESSIONS,
        );

        /** @var LiveChatSessionDomainObject $session */
        $session = $this->sessionRepository->create([
            LiveChatSessionDomainObjectAbstract::PUBLIC_ID => Str::random(48),
            LiveChatSessionDomainObjectAbstract::ACCOUNT_ID => $settings->getAccountId(),
            LiveChatSessionDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            LiveChatSessionDomainObjectAbstract::EVENT_ID => $this->eventOfOrganizer($organizerId, $eventId)?->getId(),
            LiveChatSessionDomainObjectAbstract::STATUS => LiveChatSessionStatus::OPEN->name,
            LiveChatSessionDomainObjectAbstract::IP_HASH => $ipHash,
        ]);

        return new LiveChatTurnDTO(
            session: $session,
            messages: collect(),
            greeting: self::greeting($this->isTutoiement($organizerId)),
        );
    }

    public static function greeting(bool $tutoiement): string
    {
        return $tutoiement
            ? 'Bonjour ! Pose-moi ta question sur nos événements, je te réponds tout de suite.'
            : 'Bonjour ! Posez-moi votre question sur nos événements, je vous réponds tout de suite.';
    }

    /**
     * @throws LiveChatUnavailableException
     */
    public function findSession(string $publicId): LiveChatSessionDomainObject
    {
        $session = $publicId !== '' ? $this->sessionRepository->findFirstWhere([
            LiveChatSessionDomainObjectAbstract::PUBLIC_ID => $publicId,
        ]) : null;

        if ($session === null || $this->findEnabledSettings($session->getOrganizerId()) === null) {
            throw new LiveChatUnavailableException(__('The chat is not available.'));
        }

        return $session;
    }

    /**
     * @throws LiveChatUnavailableException
     */
    public function messages(string $publicId): LiveChatTurnDTO
    {
        $session = $this->findSession($publicId);

        return new LiveChatTurnDTO(
            session: $session,
            messages: $this->messageRepository->findLatestForSession($session->getId(), self::TRANSCRIPT_LENGTH),
        );
    }

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatLimitReachedException
     * @throws Throwable
     */
    public function post(string $publicId, string $content, string $ip): LiveChatTurnDTO
    {
        $session = $this->findSession($publicId);
        $settings = $this->findEnabledSettings($session->getOrganizerId());
        $content = mb_substr(trim(strip_tags($content)), 0, self::MAX_MESSAGE_LENGTH);

        if ($session->getVisitorMessageCount() >= (int) $this->config->get('services.live_chat.max_messages_per_session', 20)) {
            throw new LiveChatLimitReachedException(
                __('This conversation has reached its message limit. Leave your contact details and the organizer will get back to you.'),
                LiveChatLimitReachedException::SESSION,
            );
        }

        $this->hit(
            'live-chat-messages:'.$session->getIpHash(),
            (int) $this->config->get('services.live_chat.max_messages_per_ip_per_hour', 60),
            LiveChatLimitReachedException::IP,
        );

        $visitorMessage = $this->addMessage($session, LiveChatAuthorType::VISITOR, $content, [
            LiveChatSessionDomainObjectAbstract::VISITOR_MESSAGE_COUNT => $session->getVisitorMessageCount() + 1,
        ]);
        $session = $this->sessionRepository->findById($session->getId());
        $contactKnown = $this->contactKnown($session);
        $sensitive = SmsSensitiveTopicDetector::isSensitive($content);

        if ($session->getSmsConversationId() !== null) {
            $this->handoffService->appendToConversation($settings, $session, $visitorMessage, $sensitive);
        }

        if (! $this->generator->isUnderDailyLimit($session->getOrganizerId())) {
            if (! $contactKnown) {
                return new LiveChatTurnDTO(session: $session, messages: collect([$visitorMessage]), contactFormFallback: true);
            }

            $holding = $this->addMessage($session, LiveChatAuthorType::AGENT, $this->isTutoiement($session->getOrganizerId())
                ? "C'est noté, l'organisateur a tes coordonnées et te répond dès que possible."
                : "C'est noté, l'organisateur a vos coordonnées et vous répond dès que possible.");
            if ($session->getSmsConversationId() !== null) {
                $this->handoffService->appendToConversation($settings, $session, $holding, true);
            }

            return new LiveChatTurnDTO(session: $session, messages: collect([$visitorMessage, $holding]));
        }

        $reply = $this->reply($session, $settings, $contactKnown);
        if ($sensitive && ! $reply->needsFollowUp && ! $reply->refundRequest) {
            $reply = new LiveChatReplyDTO(
                messages: $reply->messages,
                needsFollowUp: true,
                askContact: ! $contactKnown,
                reason: $reply->reason,
            );
        }

        $agentMessages = collect();
        foreach ($reply->messages as $index => $bubble) {
            $agentMessages->push($this->addMessage($session, LiveChatAuthorType::AGENT, $bubble, $index === 0 ? [
                LiveChatSessionDomainObjectAbstract::NEEDS_FOLLOW_UP => $session->getNeedsFollowUp() || $reply->needsFollowUp,
                LiveChatSessionDomainObjectAbstract::FOLLOW_UP_REASON => $reply->reason ?? $session->getFollowUpReason(),
            ] : [], countModelCall: $index === 0));
        }
        $session = $this->sessionRepository->findById($session->getId());

        if ($session->getSmsConversationId() !== null) {
            foreach ($agentMessages as $agentMessage) {
                $this->handoffService->appendToConversation($settings, $session, $agentMessage, $reply->needsFollowUp);
            }
        }

        return new LiveChatTurnDTO(
            session: $session,
            messages: collect([$visitorMessage, ...$agentMessages]),
            askContact: $reply->askContact && ! $contactKnown,
            refundForm: $reply->refundRequest,
        );
    }

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatInvalidContactException
     * @throws Throwable
     */
    public function submitContact(string $publicId, ?string $phone, ?string $email): LiveChatTurnDTO
    {
        $session = $this->findSession($publicId);
        $settings = $this->findEnabledSettings($session->getOrganizerId());

        $normalizedPhone = null;
        if ($phone !== null && trim($phone) !== '') {
            $normalizedPhone = SmsPhoneNormalizer::normalize($phone);
            if ($normalizedPhone === null || preg_match('/^\+[1-9]\d{7,14}$/', $normalizedPhone) !== 1) {
                throw new LiveChatInvalidContactException(__('Invalid phone number'), 'phone');
            }
        }

        $email = $email !== null && trim($email) !== '' ? Str::lower(trim($email)) : null;
        if ($normalizedPhone === null && $email === null) {
            throw new LiveChatInvalidContactException(__('Enter a phone number or an e-mail address.'), 'phone');
        }

        $tutoiement = $this->isTutoiement($session->getOrganizerId());
        $bySms = $normalizedPhone !== null && $this->handoffService->canRelayBySms($settings);
        $confirmation = match (true) {
            $bySms && $tutoiement => "Merci, c'est noté. L'organisateur te répond par SMS au {$normalizedPhone}.",
            $bySms => "Merci, c'est noté. L'organisateur vous répond par SMS au {$normalizedPhone}.",
            $normalizedPhone !== null && $tutoiement => "Merci, c'est noté. L'organisateur te recontacte au {$normalizedPhone}.",
            $normalizedPhone !== null => "Merci, c'est noté. L'organisateur vous recontacte au {$normalizedPhone}.",
            $tutoiement => "Merci, c'est noté. L'organisateur te répond par e-mail à {$email}.",
            default => "Merci, c'est noté. L'organisateur vous répond par e-mail à {$email}.",
        };

        return $this->databaseManager->transaction(function () use ($session, $settings, $normalizedPhone, $email, $confirmation, $bySms) {
            $this->sessionRepository->updateFromArray($session->getId(), [
                LiveChatSessionDomainObjectAbstract::VISITOR_PHONE => $normalizedPhone ?? $session->getVisitorPhone(),
                LiveChatSessionDomainObjectAbstract::VISITOR_EMAIL => $email ?? $session->getVisitorEmail(),
                LiveChatSessionDomainObjectAbstract::STATUS => LiveChatSessionStatus::HANDED_OFF->name,
                LiveChatSessionDomainObjectAbstract::CONTACT_GIVEN_AT => now()->toDateTimeString(),
            ]);

            $agentMessage = $this->addMessage($session, LiveChatAuthorType::AGENT, $confirmation);
            $session = $this->sessionRepository->findById($session->getId());
            $transcript = $this->messageRepository->findLatestForSession($session->getId(), self::TRANSCRIPT_LENGTH);

            if ($bySms && $session->getSmsConversationId() === null) {
                $conversation = $this->handoffService->handOffToSms($settings, $session, $normalizedPhone, $transcript);
                $session = $this->sessionRepository->updateFromArray($session->getId(), [
                    LiveChatSessionDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
                ]);
            } elseif ($session->getSmsConversationId() !== null) {
                $this->handoffService->appendToConversation($settings, $session, $agentMessage, true);
            } else {
                $this->handoffService->handOffByEmail(
                    $this->organizerRepository->findById($session->getOrganizerId()),
                    $settings,
                    $session,
                    $transcript,
                );
            }

            return new LiveChatTurnDTO(session: $session, messages: collect([$agentMessage]));
        });
    }

    /**
     * Adds a message written outside the model (refund steps) and mirrors it to the SMS conversation of the chat, if any.
     *
     * @param  array<string, mixed>  $sessionAttributes
     */
    public function record(
        LiveChatSessionDomainObject $session,
        LiveChatAuthorType $author,
        string $content,
        array $sessionAttributes = [],
        bool $needsFollowUp = false,
    ): LiveChatMessageDomainObject {
        $session = $this->sessionRepository->findById($session->getId());
        $message = $this->addMessage($session, $author, $content, [
            ...$sessionAttributes,
            ...($author === LiveChatAuthorType::VISITOR ? [
                LiveChatSessionDomainObjectAbstract::VISITOR_MESSAGE_COUNT => $session->getVisitorMessageCount() + 1,
            ] : []),
        ]);

        if ($session->getSmsConversationId() !== null) {
            $settings = $this->findEnabledSettings($session->getOrganizerId());
            if ($settings !== null) {
                $this->handoffService->appendToConversation(
                    $settings,
                    $this->sessionRepository->findById($session->getId()),
                    $message,
                    $needsFollowUp,
                );
            }
        }

        return $message;
    }

    /**
     * Hands the chat over to the organizer when the contact details of the visitor are already known
     * (for instance the e-mail of an order checked during a refund request), with the transcript.
     *
     * @throws Throwable
     */
    public function handOffKnownContact(LiveChatSessionDomainObject $session): LiveChatSessionDomainObject
    {
        $session = $this->sessionRepository->findById($session->getId());
        $settings = $this->findEnabledSettings($session->getOrganizerId());
        if ($settings === null || ! $this->contactKnown($session)) {
            return $session;
        }

        return $this->databaseManager->transaction(function () use ($session, $settings) {
            $session = $this->sessionRepository->updateFromArray($session->getId(), [
                LiveChatSessionDomainObjectAbstract::STATUS => LiveChatSessionStatus::HANDED_OFF->name,
                LiveChatSessionDomainObjectAbstract::CONTACT_GIVEN_AT => $session->getContactGivenAt() ?? now()->toDateTimeString(),
            ]);

            if ($session->getSmsConversationId() !== null) {
                return $session;
            }

            $transcript = $this->messageRepository->findLatestForSession($session->getId(), self::TRANSCRIPT_LENGTH);
            if ($session->getVisitorPhone() !== null && $this->handoffService->canRelayBySms($settings)) {
                $conversation = $this->handoffService->handOffToSms($settings, $session, $session->getVisitorPhone(), $transcript);

                return $this->sessionRepository->updateFromArray($session->getId(), [
                    LiveChatSessionDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
                ]);
            }

            $this->handoffService->handOffByEmail(
                $this->organizerRepository->findById($session->getOrganizerId()),
                $settings,
                $session,
                $transcript,
            );

            return $session;
        });
    }

    private function reply(
        LiveChatSessionDomainObject $session,
        OrganizerSmsSettingDomainObject $settings,
        bool $contactKnown,
    ): LiveChatReplyDTO {
        /** @var OrganizerDomainObject $organizer */
        $organizer = $this->organizerRepository->findById($session->getOrganizerId());
        $tutoiement = $this->isTutoiement($session->getOrganizerId());

        try {
            return $this->generator->generate(new LiveChatRequestDTO(
                organizerId: $session->getOrganizerId(),
                sessionId: $session->getId(),
                smsConversationId: $session->getSmsConversationId(),
                organizerName: $organizer->getName(),
                timezone: $organizer->getTimezone() ?: 'UTC',
                instructions: $settings->getAssistantInstructions(),
                tutoiement: $tutoiement,
                contactKnown: $contactKnown,
                currentEventId: $session->getEventId(),
                thread: $this->messageRepository->findLatestForSession($session->getId(), self::THREAD_LENGTH),
                events: $this->catalogService->getUpcomingEvents($session->getOrganizerId(), withDetails: true),
                refundAvailable: $session->getRefundedAt() === null
                    && $this->refundProtectionService->isAvailableForOrganizer($session->getOrganizerId()),
                greeting: self::greeting($tutoiement),
                handedOff: $session->getNeedsFollowUp()
                    || $session->getStatus() === LiveChatSessionStatus::HANDED_OFF->name
                    || $session->getSmsConversationId() !== null,
            ));
        } catch (SmsAssistantUnavailableException|SmsAssistantFailedException|SmsAssistantLimitReachedException) {
            $replies = $tutoiement ? self::HOLDING_REPLIES_TU : self::HOLDING_REPLIES_VOUS;

            return new LiveChatReplyDTO(
                messages: [$replies[$session->getVisitorMessageCount() % count($replies)]],
                needsFollowUp: true,
                askContact: ! $contactKnown,
                reason: __('the assistant could not answer'),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $sessionAttributes
     */
    private function addMessage(
        LiveChatSessionDomainObject $session,
        LiveChatAuthorType $author,
        string $content,
        array $sessionAttributes = [],
        bool $countModelCall = false,
    ): LiveChatMessageDomainObject {
        /** @var LiveChatMessageDomainObject $message */
        $message = $this->messageRepository->create([
            LiveChatMessageDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
            LiveChatMessageDomainObjectAbstract::AUTHOR_TYPE => $author->name,
            LiveChatMessageDomainObjectAbstract::CONTENT => $content,
        ]);

        $this->sessionRepository->updateFromArray($session->getId(), [
            ...$sessionAttributes,
            LiveChatSessionDomainObjectAbstract::LAST_MESSAGE_AT => now()->toDateTimeString(),
            ...($countModelCall ? [
                LiveChatSessionDomainObjectAbstract::MODEL_CALL_COUNT => $session->getModelCallCount() + 1,
            ] : []),
        ]);

        return $message;
    }

    private function contactKnown(LiveChatSessionDomainObject $session): bool
    {
        return $session->getVisitorPhone() !== null || $session->getVisitorEmail() !== null;
    }

    /**
     * @throws LiveChatLimitReachedException
     */
    private function hit(string $key, int $max, string $limit): void
    {
        if ($max <= 0) {
            return;
        }

        if ($this->rateLimiter->tooManyAttempts($key, $max)) {
            throw new LiveChatLimitReachedException(
                __('Too many messages for now. Leave your contact details or try again later.'),
                $limit,
            );
        }

        $this->rateLimiter->hit($key, self::RATE_WINDOW_SECONDS);
    }

    private function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) $this->config->get('app.key'));
    }

    private function findEnabledSettings(int $organizerId): ?OrganizerSmsSettingDomainObject
    {
        if (! $this->generator->isConfigured()) {
            return null;
        }

        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($settings !== null) {
            return $settings->getLiveChatEnabled() ? $settings : null;
        }

        return $this->defaultSettings($organizerId);
    }

    /**
     * An organizer who never opened the SMS tab has no settings row: the chat is on by default and, without an SMS line,
     * every hand-over goes to the organizer by e-mail. Nothing is written for a public visit.
     */
    private function defaultSettings(int $organizerId): ?OrganizerSmsSettingDomainObject
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere(['id' => $organizerId]);
        if ($organizer === null) {
            return null;
        }

        return (new OrganizerSmsSettingDomainObject)
            ->setOrganizerId($organizerId)
            ->setAccountId($organizer->getAccountId())
            ->setLiveChatEnabled(true)
            ->setNotifyOnInboundSms(true);
    }

    private function eventOfOrganizer(int $organizerId, ?int $eventId): ?EventDomainObject
    {
        if ($eventId === null) {
            return null;
        }

        return $this->eventRepository->findFirstWhere([
            EventDomainObjectAbstract::ID => $eventId,
            EventDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);
    }

    private function isTutoiement(int $organizerId): bool
    {
        return $this->customerLocaleResolver->addressFormForOrganizerId($organizerId) === CustomerAddressForm::TU;
    }
}
