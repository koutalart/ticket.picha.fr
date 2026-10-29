<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\Enums\LiveChatStyle;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatQuestionDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\Status\LiveChatQuestionStatus;
use HiEvents\DomainObjects\Status\LiveChatSessionStatus;
use HiEvents\Exceptions\LiveChatInvalidContactException;
use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Helper\SmsSensitiveTopicDetector;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatConfigDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatReplyDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatRequestDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatTurnDTO;
use HiEvents\Services\Domain\LiveChat\Knowledge\EventAssistantKnowledgeService;
use HiEvents\Services\Domain\LiveChat\Question\LiveChatQuestionService;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundProtectionService;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
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

    public const CONTACT_FROM_MESSAGE = 3;

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

    private const CONTACT_FOR_QUESTION_VOUS = [
        "Je la transmets à l'organisateur. Vous me laissez votre numéro ou votre e-mail pour qu'il puisse vous répondre ?",
        "Je pose la question à l'organisateur. Laissez-moi un numéro ou un e-mail pour que sa réponse vous arrive.",
        "Je transmets à l'organisateur. Pour qu'il vous réponde, il me faut votre numéro de téléphone ou votre e-mail.",
    ];

    private const CONTACT_FOR_QUESTION_TU = [
        "Je la transmets à l'organisateur. Tu me laisses ton numéro ou ton e-mail pour qu'il puisse te répondre ?",
        "Je pose la question à l'organisateur. Laisse-moi un numéro ou un e-mail pour que sa réponse t'arrive.",
        "Je transmets à l'organisateur. Pour qu'il te réponde, il me faut ton numéro de téléphone ou ton e-mail.",
    ];

    private const CONTACT_FOR_FOLLOW_UP_VOUS = [
        'Vous me laissez votre numéro ou votre e-mail pour que l\'organisateur puisse vous répondre ?',
        'Laissez-moi un numéro ou un e-mail, c\'est pour que l\'organisateur puisse revenir vers vous.',
    ];

    private const CONTACT_FOR_FOLLOW_UP_TU = [
        'Tu me laisses ton numéro ou ton e-mail pour que l\'organisateur puisse te répondre ?',
        'Laisse-moi un numéro ou un e-mail, c\'est pour que l\'organisateur puisse revenir vers toi.',
    ];

    /**
     * Sensitive topics on which a general question (minimum age, access for a wheelchair) still goes to the organizer
     * as a question: the organizer answers it himself, nothing is answered automatically.
     */
    private const TOPICS_ASKED_AS_QUESTION = ['minor', 'accessibility'];

    private const CONTACT_AGAIN_VOUS = [
        'Juste un numéro ou un e-mail dans le petit formulaire en dessous, et la réponse de l\'organisateur vous arrive directement.',
        'Vous pouvez me laisser un numéro ou un e-mail juste en dessous ? C\'est pour que l\'organisateur puisse vous répondre.',
    ];

    private const CONTACT_AGAIN_TU = [
        'Juste un numéro ou un e-mail dans le petit formulaire en dessous, et la réponse de l\'organisateur t\'arrive directement.',
        'Tu peux me laisser un numéro ou un e-mail juste en dessous ? C\'est pour que l\'organisateur puisse te répondre.',
    ];

    private const WITHOUT_CONTACT_VOUS = [
        'Pas de souci, je transmets quand même votre question à l\'organisateur. Sans numéro ni e-mail, on ne pourra pas vous prévenir : sa réponse s\'affichera ici, si vous revenez sur cette page depuis le même appareil.',
        'D\'accord, je transmets votre question à l\'organisateur. Comme je n\'ai pas de quoi vous joindre, sa réponse s\'affichera ici quand vous reviendrez sur cette page, depuis le même appareil.',
    ];

    private const WITHOUT_CONTACT_TU = [
        'Pas de souci, je transmets quand même ta question à l\'organisateur. Sans numéro ni e-mail, on ne pourra pas te prévenir : sa réponse s\'affichera ici, si tu reviens sur cette page depuis le même appareil.',
        'D\'accord, je transmets ta question à l\'organisateur. Comme je n\'ai pas de quoi te joindre, sa réponse s\'affichera ici quand tu reviendras sur cette page, depuis le même appareil.',
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
        private readonly LiveChatQuestionService $questionService,
        private readonly EventAssistantKnowledgeService $knowledgeService,
        private readonly ImageRepositoryInterface $imageRepository,
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
            style: LiveChatStyle::tryFrom((string) $settings->getLiveChatStyle()) ?? LiveChatStyle::CLASSIC,
            avatarUrl: $this->avatarUrl($organizerId),
        );
    }

    private function avatarUrl(int $organizerId): ?string
    {
        /** @var ImageDomainObject|null $avatar */
        $avatar = $this->imageRepository->findFirstWhere([
            ImageDomainObjectAbstract::ENTITY_ID => $organizerId,
            ImageDomainObjectAbstract::ENTITY_TYPE => OrganizerDomainObject::class,
            ImageDomainObjectAbstract::TYPE => ImageType::ORGANIZER_CHAT_AVATAR->name,
        ]);

        return $avatar !== null && ! empty($avatar->getPath()) ? Url::getCdnUrl($avatar->getPath()) : null;
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
            askContact: $this->contactFormShown($session),
            pendingQuestion: $this->questionService->openForSession($session->getId())->isNotEmpty(),
        );
    }

    /**
     * Contact details are asked from the third message of the visitor, unless they offer them or ask to be called
     * back; never after a refusal. Once asked, the small form stays until given or refused.
     */
    public static function contactAllowed(int $visitorMessageCount, string $lastVisitorMessage, bool $contactKnown, bool $declined): bool
    {
        if ($contactKnown || $declined) {
            return false;
        }

        return $visitorMessageCount >= self::CONTACT_FROM_MESSAGE || self::offersContact($lastVisitorMessage);
    }

    public static function offersContact(string $message): bool
    {
        return preg_match('/(\+?\d[\d\s.\-]{7,}\d)|([^\s@]+@[^\s@]+\.[^\s@]+)|\b(rappel|rappeler|rappelle|recontact|appelle[rz]?|appel|joindre|mon num|mon numéro|mon tel|mon tél|mon mail|mon e-?mail)/iu', $message) === 1;
    }

    public static function refusesContact(string $message): bool
    {
        return preg_match('/^\s*(non|nan|nope|no|pas besoin|non merci|ça ira|ca ira|sans façon|je (ne )?pr[ée]f[èe]re pas|pas maintenant|pas la peine|pas de num[ée]ro|je (ne )?veux pas)\b/iu', $message) === 1;
    }

    private function contactFormShown(LiveChatSessionDomainObject $session): bool
    {
        return $session->getContactAskedAt() !== null
            && $session->getContactDeclinedAt() === null
            && ! $this->contactKnown($session);
    }

    private function contactPolicy(LiveChatSessionDomainObject $session, string $lastVisitorMessage, bool $contactKnown): string
    {
        return match (true) {
            $contactKnown => 'inutile, elles sont déjà connues',
            $session->getContactDeclinedAt() !== null => 'refusée par le visiteur, ne la refais jamais',
            $session->getContactAskedAt() !== null => 'déjà faite, le formulaire reste affiché, ne la refais pas dans le texte',
            ! self::contactAllowed($session->getVisitorMessageCount(), $lastVisitorMessage, false, false) => sprintf(
                'pas encore (avant le %de message du visiteur), sauf s\'il propose lui-même son numéro, demande à être rappelé, ou si tu transmets sa demande ou une question à l\'organisateur',
                self::CONTACT_FROM_MESSAGE,
            ),
            default => 'permise si utile',
        };
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
        $sensitiveTopic = SmsSensitiveTopicDetector::detect($content);
        $sensitive = $sensitiveTopic !== null;

        if (! $contactKnown && $session->getContactAskedAt() !== null && $session->getContactDeclinedAt() === null
            && self::refusesContact($content)) {
            $session = $this->sessionRepository->updateFromArray($session->getId(), [
                LiveChatSessionDomainObjectAbstract::CONTACT_DECLINED_AT => now()->toDateTimeString(),
            ]);
        }

        if ($session->getSmsConversationId() !== null) {
            $this->handoffService->appendToConversation($settings, $session, $visitorMessage, $sensitive);
        }

        $held = $contactKnown ? collect() : $this->questionService->heldForSession($session->getId());
        $typedContact = $held->isNotEmpty() ? self::extractContact($content) : null;
        if ($typedContact !== null) {
            return $this->forwardWithTypedContact($session, $settings, $visitorMessage, $typedContact['phone'], $typedContact['email']);
        }

        $forwardedWithoutContact = false;
        $contactAgain = false;
        if ($held->isNotEmpty()) {
            $requests = (int) $held->max(fn (LiveChatQuestionDomainObject $question) => $question->getContactRequests());
            if ($session->getContactDeclinedAt() !== null || $requests >= LiveChatQuestionService::MAX_CONTACT_REQUESTS) {
                $this->questionService->releaseHeld($session->getId());
                $session = $this->sessionRepository->findById($session->getId());
                $forwardedWithoutContact = true;
            } else {
                $contactAgain = true;
            }
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

        $reply = $this->reply($session, $settings, $contactKnown, $content, match (true) {
            $contactAgain => 'Ta question à l\'organisateur attend encore son numéro ou son e-mail : redemande-les une seule fois, poliment et autrement que la première fois, en une phrase (le petit formulaire est affiché sous ta réponse).',
            $forwardedWithoutContact => 'Sa question vient de partir chez l\'organisateur sans coordonnées. Une phrase l\'explique automatiquement après ta réponse : ne parle ni de coordonnées ni de transmission.',
            default => null,
        });
        $askedAsQuestion = $reply->questionForOrganizer !== null && in_array($sensitiveTopic, self::TOPICS_ASKED_AS_QUESTION, true);
        if ($sensitive && ! $askedAsQuestion && ! $reply->needsFollowUp && ! $reply->refundRequest) {
            $reply = new LiveChatReplyDTO(
                messages: $reply->messages,
                needsFollowUp: true,
                askContact: ! $contactKnown,
                reason: $reply->reason,
            );
        }

        $declined = $session->getContactDeclinedAt() !== null;
        $contactForQuestion = false;
        if ($reply->questionForOrganizer !== null && ! $reply->needsFollowUp && ! $reply->refundRequest) {
            $openIds = $this->questionService->openForSession($session->getId())
                ->map(fn (LiveChatQuestionDomainObject $question) => $question->getId())
                ->all();
            $asked = $this->questionService->ask($session, $reply->questionForOrganizer, $content, holdForContact: ! $contactKnown && ! $declined);

            if ($asked === null) {
                $reply = new LiveChatReplyDTO(
                    messages: $reply->messages,
                    needsFollowUp: true,
                    askContact: ! $contactKnown,
                    reason: mb_substr(__('question to check: :question', ['question' => $reply->questionForOrganizer]), 0, 255),
                );
            } elseif (! in_array($asked->getId(), $openIds, true)) {
                $contactForQuestion = $asked->getStatus() === LiveChatQuestionStatus::AWAITING_CONTACT->name;
                $forwardedWithoutContact = $forwardedWithoutContact || (! $contactKnown && ! $contactForQuestion);
            }
        }

        $tutoiement = $this->isTutoiement($session->getOrganizerId());
        $variant = $session->getModelCallCount();
        $bubbles = $reply->messages;
        if ($contactForQuestion && ! $contactAgain) {
            $bubbles = self::withContactRequest($bubbles, self::pick($tutoiement ? self::CONTACT_FOR_QUESTION_TU : self::CONTACT_FOR_QUESTION_VOUS, $variant));
        } elseif ($contactAgain) {
            $bubbles = self::withContactRequest($bubbles, self::pick($tutoiement ? self::CONTACT_AGAIN_TU : self::CONTACT_AGAIN_VOUS, $variant));
            $this->questionService->markContactRequested($session->getId());
        }
        $contactForFollowUp = $reply->needsFollowUp && ! $reply->refundRequest && ! $contactKnown && ! $declined
            && ! $contactForQuestion && ! $contactAgain && $session->getContactAskedAt() === null;
        if ($contactForFollowUp) {
            $bubbles = self::withContactRequest($bubbles, self::pick($tutoiement ? self::CONTACT_FOR_FOLLOW_UP_TU : self::CONTACT_FOR_FOLLOW_UP_VOUS, $variant));
        }
        if ($forwardedWithoutContact) {
            $bubbles = self::withExtraBubble($bubbles, self::pick($tutoiement ? self::WITHOUT_CONTACT_TU : self::WITHOUT_CONTACT_VOUS, $variant));
        }

        $askContact = ! $reply->refundRequest
            && ! $contactKnown
            && ! $declined
            && ($contactForQuestion || $contactAgain || $contactForFollowUp || $session->getContactAskedAt() !== null || (
                self::contactAllowed($session->getVisitorMessageCount(), $content, $contactKnown, $declined)
                && $reply->askContact
            ));

        $agentMessages = collect();
        foreach ($bubbles as $index => $bubble) {
            $agentMessages->push($this->addMessage($session, LiveChatAuthorType::AGENT, $bubble, $index === 0 ? [
                LiveChatSessionDomainObjectAbstract::NEEDS_FOLLOW_UP => $session->getNeedsFollowUp() || $reply->needsFollowUp,
                LiveChatSessionDomainObjectAbstract::FOLLOW_UP_REASON => $reply->reason ?? $session->getFollowUpReason(),
                LiveChatSessionDomainObjectAbstract::CONTACT_ASKED_AT => $session->getContactAskedAt()
                    ?? ($askContact ? now()->toDateTimeString() : null),
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
            askContact: $askContact,
            refundForm: $reply->refundRequest,
            pendingQuestion: $this->questionService->openForSession($session->getId())->isNotEmpty(),
        );
    }

    /**
     * A phone number or an e-mail written in the message itself, valid as in the contact form.
     *
     * @return array{phone: ?string, email: ?string}|null
     */
    public static function extractContact(string $message): ?array
    {
        $email = preg_match('/[^\s@<>()"\',;:]+@[^\s@<>()"\',;:]+\.[a-z]{2,}/iu', $message, $match) === 1
            ? Str::lower(rtrim($match[0], '.'))
            : null;

        $phone = null;
        if (preg_match('/\+?\d[\d\s.\-]{7,}\d/u', $message, $match) === 1) {
            $phone = SmsPhoneNormalizer::normalize($match[0]);
            if ($phone !== null && preg_match('/^\+[1-9]\d{7,14}$/', $phone) !== 1) {
                $phone = null;
            }
        }

        return $phone === null && $email === null ? null : ['phone' => $phone, 'email' => $email];
    }

    public static function asksForContact(string $text): bool
    {
        return preg_match('/num[ée]ro|t[ée]l[ée]phone|\bt[ée]l\b|e-?mail|\bmail\b|coordonn[ée]es/iu', $text) === 1;
    }

    /**
     * Makes sure the reply asks for a phone number or an e-mail: the line is added only when no bubble does.
     *
     * @param  list<string>  $bubbles
     * @return list<string>
     */
    public static function withContactRequest(array $bubbles, string $line): array
    {
        foreach ($bubbles as $bubble) {
            if (self::asksForContact($bubble)) {
                return $bubbles;
            }
        }

        return self::withExtraBubble($bubbles, $line);
    }

    /**
     * @param  list<string>  $bubbles
     * @return list<string>
     */
    public static function withExtraBubble(array $bubbles, string $line): array
    {
        if (count($bubbles) < LiveChatReplyGenerator::MAX_BUBBLES) {
            return [...$bubbles, $line];
        }

        $last = count($bubbles) - 1;
        $bubbles[$last] .= ' '.$line;

        return $bubbles;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function pick(array $lines, int $variant): string
    {
        return $lines[abs($variant) % count($lines)];
    }

    /**
     * The visitor wrote their number or e-mail in the chat after being asked for it about a question: they are kept
     * as the contact details of the chat and the held questions leave for the organizer at once.
     *
     * @throws Throwable
     */
    private function forwardWithTypedContact(
        LiveChatSessionDomainObject $session,
        OrganizerSmsSettingDomainObject $settings,
        LiveChatMessageDomainObject $visitorMessage,
        ?string $phone,
        ?string $email,
    ): LiveChatTurnDTO {
        $session = $this->sessionRepository->updateFromArray($session->getId(), [
            LiveChatSessionDomainObjectAbstract::VISITOR_PHONE => $phone ?? $session->getVisitorPhone(),
            LiveChatSessionDomainObjectAbstract::VISITOR_EMAIL => $email ?? $session->getVisitorEmail(),
            LiveChatSessionDomainObjectAbstract::CONTACT_GIVEN_AT => now()->toDateTimeString(),
        ]);

        $held = $this->questionService->heldForSession($session->getId())->count();
        $sent = $this->questionService->releaseHeld($session->getId());

        $agentMessage = $this->addMessage(
            $session,
            LiveChatAuthorType::AGENT,
            self::forwardConfirmation($this->isTutoiement($session->getOrganizerId()), $phone, $email),
        );
        $session = $this->sessionRepository->findById($session->getId());

        if ($session->getSmsConversationId() !== null) {
            $this->handoffService->appendToConversation($settings, $session, $agentMessage, $sent < $held);
        } elseif ($sent < $held) {
            $session = $this->handOffKnownContact($session);
        }

        return new LiveChatTurnDTO(
            session: $session,
            messages: collect([$visitorMessage, $agentMessage]),
            pendingQuestion: $this->questionService->openForSession($session->getId())->isNotEmpty(),
        );
    }

    public static function forwardConfirmation(bool $tutoiement, ?string $phone, ?string $email): string
    {
        return match (true) {
            $phone !== null && $tutoiement => "Merci, je transmets ta question à l'organisateur, il te répond au {$phone}.",
            $phone !== null => "Merci, je transmets votre question à l'organisateur, il vous répond au {$phone}.",
            $tutoiement => "Merci, je transmets ta question à l'organisateur, il te répond par e-mail à {$email}.",
            default => "Merci, je transmets votre question à l'organisateur, il vous répond par e-mail à {$email}.",
        };
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
        $hasHeldQuestion = $this->questionService->heldForSession($session->getId())->isNotEmpty();
        $confirmation = match (true) {
            $hasHeldQuestion => self::forwardConfirmation($tutoiement, $normalizedPhone, $email),
            $bySms && $tutoiement => "Merci, c'est noté. L'organisateur te répond par SMS au {$normalizedPhone}.",
            $bySms => "Merci, c'est noté. L'organisateur vous répond par SMS au {$normalizedPhone}.",
            $normalizedPhone !== null && $tutoiement => "Merci, c'est noté. L'organisateur te recontacte au {$normalizedPhone}.",
            $normalizedPhone !== null => "Merci, c'est noté. L'organisateur vous recontacte au {$normalizedPhone}.",
            $tutoiement => "Merci, c'est noté. L'organisateur te répond par e-mail à {$email}.",
            default => "Merci, c'est noté. L'organisateur vous répond par e-mail à {$email}.",
        };

        $turn = $this->databaseManager->transaction(function () use ($session, $settings, $normalizedPhone, $email, $confirmation, $bySms) {
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

        if (! $hasHeldQuestion) {
            return $turn;
        }

        $this->questionService->releaseHeld($session->getId());

        return new LiveChatTurnDTO(
            session: $this->sessionRepository->findById($session->getId()),
            messages: $turn->messages,
            pendingQuestion: $this->questionService->openForSession($session->getId())->isNotEmpty(),
        );
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
        string $content,
        ?string $questionContactNote = null,
    ): LiveChatReplyDTO {
        /** @var OrganizerDomainObject $organizer */
        $organizer = $this->organizerRepository->findById($session->getOrganizerId());
        $tutoiement = $this->isTutoiement($session->getOrganizerId());
        $events = $this->catalogService->getUpcomingEvents($session->getOrganizerId(), withDetails: true);

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
                events: $events,
                refundAvailable: $session->getRefundedAt() === null
                    && $this->refundProtectionService->isAvailableForOrganizer($session->getOrganizerId()),
                greeting: self::greeting($tutoiement),
                handedOff: $session->getNeedsFollowUp()
                    || $session->getStatus() === LiveChatSessionStatus::HANDED_OFF->name
                    || $session->getSmsConversationId() !== null,
                visitorMessageNumber: $session->getVisitorMessageCount(),
                contactPolicy: $this->contactPolicy($session, $content, $contactKnown),
                pendingQuestions: $this->questionService->openForSession($session->getId())
                    ->map(fn ($question) => $question->getQuestion())
                    ->values()
                    ->all(),
                knowledge: $this->knowledgeService->forEvents(
                    $session->getOrganizerId(),
                    $events->map(fn (SmsAssistantEventInfoDTO $event) => $event->id)->filter()->values()->all(),
                ),
                publications: $this->knowledgeService->publications($session->getOrganizerId()),
                questionContactNote: $questionContactNote,
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
            ->setLiveChatStyle(LiveChatStyle::CLASSIC->value)
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
