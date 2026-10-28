<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Question;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatQuestionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatQuestionDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\LiveChatQuestionStatus;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Jobs\LiveChat\RelayLiveChatAnswerJob;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatQuestionRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalSchedule;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisionAlertService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisorService;
use HiEvents\Services\Domain\Sms\Team\SmsDirectSendService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * A visitor question the assistant cannot answer from the data goes to the organizer by SMS, numbered « Q12 », from
 * the organizer's line to its ORGANIZER team contacts (never COPY). This is an
 * outgoing alert, not an automatic reply of a conversation. The organizer answers by replying to that SMS, or with
 * « Q12 » and the answer; the answer goes back to the visitor and becomes a confirmed fact of the event.
 * Without answer within 2 hours (counted between 8:00 and 21:00 Mayotte time), one reminder is sent and the
 * supervision is alerted.
 *
 * Before it is sent, a question waits for the contact details of the visitor (AWAITING_CONTACT), so that someone can
 * answer them: it leaves as soon as they are given, after a refusal or a second request without them, or after
 * HOLD_MINUTES when the visitor has left. The SMS to the organizer always says how to reach the visitor.
 */
class LiveChatQuestionService
{
    public const MAX_AWAITING_PER_SESSION = 3;

    public const REPLY_WINDOW_HOURS = 24;

    public const REMINDER_HOURS = 2;

    public const HOLD_MINUTES = 10;

    public const MAX_CONTACT_REQUESTS = 2;

    private const MAX_QUESTION_LENGTH = 300;

    private const BATCH = 100;

    public function __construct(
        private readonly LiveChatQuestionRepositoryInterface $questionRepository,
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly PlatformSupervisorService $supervisorService,
        private readonly PlatformSupervisionAlertService $supervisionAlertService,
        private readonly SmsDirectSendService $directSendService,
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public static function reference(LiveChatQuestionDomainObject|int $question): string
    {
        return 'Q'.($question instanceof LiveChatQuestionDomainObject ? $question->getId() : $question);
    }

    /**
     * « Q12 il est remplacé par Terrell », « q 12 : oui » : the question id and the answer, or null.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function parseReference(string $content): ?array
    {
        if (preg_match('/^\s*q\s*(\d{1,9})\b[\s:,.\-]*(.*)$/isu', $content, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], trim($matches[2])];
    }

    /**
     * A plain « ok », « merci » or a thumb is an acknowledgement, not an answer.
     */
    public static function isAnswer(string $content): bool
    {
        $content = trim($content);

        return mb_strlen($content) >= 2
            && preg_match('/^(ok|okay|oki|d\'?accord|merci|vu|re[çc]u|top|parfait|super|👍|🙏|👌)[\s!.]*$/iu', $content) !== 1;
    }

    /**
     * @return Collection<int, LiveChatQuestionDomainObject>
     */
    public function awaitingForSession(int $sessionId): Collection
    {
        return $this->questionRepository->findWhere(
            [
                LiveChatQuestionDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $sessionId,
                LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING->name,
            ],
            orderAndDirections: [new OrderAndDirection(LiveChatQuestionDomainObjectAbstract::ID, 'asc')],
        );
    }

    /**
     * Questions kept until the visitor gives a phone number or an e-mail.
     *
     * @return Collection<int, LiveChatQuestionDomainObject>
     */
    public function heldForSession(int $sessionId): Collection
    {
        return $this->questionRepository->findWhere(
            [
                LiveChatQuestionDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $sessionId,
                LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING_CONTACT->name,
            ],
            orderAndDirections: [new OrderAndDirection(LiveChatQuestionDomainObjectAbstract::ID, 'asc')],
        );
    }

    /**
     * Held and sent questions without answer yet: the assistant must not ask them again.
     *
     * @return Collection<int, LiveChatQuestionDomainObject>
     */
    public function openForSession(int $sessionId): Collection
    {
        return $this->heldForSession($sessionId)->concat($this->awaitingForSession($sessionId))->values();
    }

    public function find(int $questionId): ?LiveChatQuestionDomainObject
    {
        return $this->questionRepository->findFirstWhere([LiveChatQuestionDomainObjectAbstract::ID => $questionId]);
    }

    /**
     * Sends the question to the organizer, or keeps it until the visitor gives contact details ($holdForContact).
     * Returns null when nobody could be reached (no SMS line): the chat then falls back on the usual hand-over to the
     * organizer.
     */
    public function ask(
        LiveChatSessionDomainObject $session,
        string $question,
        string $visitorMessage,
        ?Carbon $now = null,
        bool $holdForContact = false,
    ): ?LiveChatQuestionDomainObject {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $question = mb_substr(trim($question), 0, self::MAX_QUESTION_LENGTH);
        if ($question === '') {
            return null;
        }

        $awaiting = $this->openForSession($session->getId());
        $same = $awaiting->first(fn (LiveChatQuestionDomainObject $existing) => self::normalize($existing->getQuestion()) === self::normalize($question));
        if ($same !== null) {
            return $same;
        }
        if ($awaiting->count() >= self::MAX_AWAITING_PER_SESSION) {
            return $awaiting->last();
        }

        /** @var LiveChatQuestionDomainObject $created */
        $created = $this->questionRepository->create([
            LiveChatQuestionDomainObjectAbstract::ORGANIZER_ID => $session->getOrganizerId(),
            LiveChatQuestionDomainObjectAbstract::EVENT_ID => $session->getEventId(),
            LiveChatQuestionDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
            LiveChatQuestionDomainObjectAbstract::QUESTION => $question,
            LiveChatQuestionDomainObjectAbstract::VISITOR_MESSAGE => mb_substr($visitorMessage, 0, 1000),
            LiveChatQuestionDomainObjectAbstract::STATUS => $holdForContact
                ? LiveChatQuestionStatus::AWAITING_CONTACT->name
                : LiveChatQuestionStatus::AWAITING->name,
            LiveChatQuestionDomainObjectAbstract::CONTACT_REQUESTS => $holdForContact ? 1 : 0,
        ]);

        if ($holdForContact) {
            return $this->find($created->getId());
        }

        if (! $this->notify($created, false, $now)) {
            $this->questionRepository->deleteById($created->getId());

            return null;
        }

        return $this->find($created->getId());
    }

    /**
     * The visitor was asked once more for contact details about the held questions.
     */
    public function markContactRequested(int $sessionId): void
    {
        foreach ($this->heldForSession($sessionId) as $question) {
            /** @var LiveChatQuestionDomainObject $question */
            $this->questionRepository->updateFromArray($question->getId(), [
                LiveChatQuestionDomainObjectAbstract::CONTACT_REQUESTS => $question->getContactRequests() + 1,
            ]);
        }
    }

    /**
     * Sends the held questions of the chat to the organizer, with the contact details known by then. A question that
     * cannot be sent (no SMS line) is removed and the chat is marked to follow up.
     *
     * @return int The number of questions sent
     */
    public function releaseHeld(int $sessionId, ?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $sent = 0;

        foreach ($this->heldForSession($sessionId) as $question) {
            /** @var LiveChatQuestionDomainObject $question */
            if ($this->release($question, $now)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Handles an SMS received from a team number: « Q12 … », or a reply right after the question SMS. Only an
     * ORGANIZER contact of the organizer of the question can answer. Returns false when the SMS is not an answer, so
     * that it follows the usual team flow.
     */
    public function handleTeamSms(OrganizerSmsSettingDomainObject $settings, SmsMessageDomainObject $message, string $contact, ?Carbon $now = null): bool
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $phone = SmsPhoneNormalizer::normalize($contact) ?? $contact;
        $organizerId = $settings->getOrganizerId();

        $teamContact = $this->teamContactService->find($organizerId, $phone);
        if ($teamContact === null || $teamContact->getRole() !== OrganizerTeamContactRole::ORGANIZER->name) {
            return false;
        }

        $content = (string) $message->getContent();
        $reference = self::parseReference($content);
        if ($reference !== null) {
            [$questionId, $answer] = $reference;
            $question = $this->find($questionId);
            if ($question === null || $question->getOrganizerId() !== $organizerId || ! self::isAnswer($answer)) {
                return false;
            }

            $this->answer($question, $answer, $phone, $now);

            return true;
        }

        if (! self::isAnswer($content)) {
            return false;
        }

        $question = $this->questionAwaitingReplyFrom($organizerId, $phone, $message, $now);
        if ($question === null) {
            return false;
        }

        $this->answer($question, trim($content), $phone, $now);

        return true;
    }

    /**
     * A platform supervisor answers only with the reference: « Q12 … ».
     *
     * @param  array<int, int>  $organizerIds  The organizers of the line the SMS came in on
     */
    public function findForSupervisorAnswer(array $organizerIds, string $content): ?LiveChatQuestionDomainObject
    {
        $reference = self::parseReference($content);
        if ($reference === null || ! self::isAnswer($reference[1])) {
            return null;
        }

        $question = $this->find($reference[0]);

        return $question !== null && in_array($question->getOrganizerId(), $organizerIds, true) ? $question : null;
    }

    public function answerFromSupervisor(LiveChatQuestionDomainObject $question, string $content, string $phone, ?Carbon $now = null): void
    {
        $reference = self::parseReference($content);
        $this->answer($question, $reference[1] ?? trim($content), SmsPhoneNormalizer::normalize($phone) ?? $phone, ($now ?? Carbon::now('UTC'))->copy()->utc());
    }

    /**
     * One reminder 2 hours after the question (between 8:00 and 21:00 in Mayotte) and an alert to the supervision.
     *
     * @return int The number of reminders sent
     */
    public function processDue(?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $sent = 0;

        $this->releaseAbandoned($now);

        $due = $this->questionRepository->findWhere([
            LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING->name,
            LiveChatQuestionDomainObjectAbstract::REMINDERS_COUNT => 0,
            [LiveChatQuestionDomainObjectAbstract::NEXT_REMINDER_AT, '<=', $now->toDateTimeString()],
        ])->take(self::BATCH);

        foreach ($due as $question) {
            /** @var LiveChatQuestionDomainObject $question */
            $claimed = $this->questionRepository->updateWhere([
                LiveChatQuestionDomainObjectAbstract::REMINDERS_COUNT => 1,
                LiveChatQuestionDomainObjectAbstract::NEXT_REMINDER_AT => null,
                LiveChatQuestionDomainObjectAbstract::ESCALATED_AT => $now->toDateTimeString(),
            ], [
                LiveChatQuestionDomainObjectAbstract::ID => $question->getId(),
                LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING->name,
                LiveChatQuestionDomainObjectAbstract::REMINDERS_COUNT => 0,
            ]);
            if ($claimed !== 1) {
                continue;
            }

            $this->notify($question, true, $now);
            $this->alertSupervision($question);
            $sent++;
        }

        return $sent;
    }

    public function nextReminderAt(Carbon $sentAt): Carbon
    {
        return RefundApprovalSchedule::withinDay($sentAt->copy()->addHours(self::REMINDER_HOURS));
    }

    /**
     * A visitor who left without giving contact details: the question still goes to the organizer, the answer will
     * be shown in the chat if they come back.
     */
    private function releaseAbandoned(Carbon $now): void
    {
        $held = $this->questionRepository->findWhere([
            LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING_CONTACT->name,
            [LiveChatQuestionDomainObjectAbstract::CREATED_AT, '<=', $now->copy()->subMinutes(self::HOLD_MINUTES)->toDateTimeString()],
        ])->take(self::BATCH);

        foreach ($held as $question) {
            /** @var LiveChatQuestionDomainObject $question */
            $this->release($question, $now);
        }
    }

    private function release(LiveChatQuestionDomainObject $question, Carbon $now): bool
    {
        $claimed = $this->questionRepository->updateWhere([
            LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING->name,
        ], [
            LiveChatQuestionDomainObjectAbstract::ID => $question->getId(),
            LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING_CONTACT->name,
        ]);
        if ($claimed !== 1) {
            return false;
        }

        if ($this->notify($question, false, $now)) {
            return true;
        }

        $this->questionRepository->deleteById($question->getId());
        if ($question->getLiveChatSessionId() !== null) {
            $this->sessionRepository->updateFromArray($question->getLiveChatSessionId(), [
                LiveChatSessionDomainObjectAbstract::NEEDS_FOLLOW_UP => true,
                LiveChatSessionDomainObjectAbstract::FOLLOW_UP_REASON => mb_substr(__('question to check: :question', ['question' => $question->getQuestion()]), 0, 255),
            ]);
        }

        return false;
    }

    private function answer(LiveChatQuestionDomainObject $question, string $answer, string $phone, Carbon $now): void
    {
        $this->questionRepository->updateFromArray($question->getId(), [
            LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::ANSWERED->name,
            LiveChatQuestionDomainObjectAbstract::ANSWER => mb_substr($answer, 0, 2000),
            LiveChatQuestionDomainObjectAbstract::ANSWERED_BY_PHONE => mb_substr($phone, 0, 20),
            LiveChatQuestionDomainObjectAbstract::ANSWERED_AT => $now->toDateTimeString(),
            LiveChatQuestionDomainObjectAbstract::NEXT_REMINDER_AT => null,
        ]);

        $this->logger->info('Live chat question answered', [
            'live_chat_question_id' => $question->getId(),
            'organizer_id' => $question->getOrganizerId(),
        ]);

        $this->dispatcher->dispatch(new RelayLiveChatAnswerJob($question->getId()));
    }

    private function questionAwaitingReplyFrom(int $organizerId, string $phone, SmsMessageDomainObject $message, Carbon $now): ?LiveChatQuestionDomainObject
    {
        $candidates = $this->questionRepository->findWhere(
            [
                LiveChatQuestionDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::AWAITING->name,
                [LiveChatQuestionDomainObjectAbstract::LAST_NOTIFIED_AT, '>=', $now->copy()->subHours(self::REPLY_WINDOW_HOURS)->toDateTimeString()],
            ],
            orderAndDirections: [new OrderAndDirection(LiveChatQuestionDomainObjectAbstract::LAST_NOTIFIED_AT, 'desc')],
        );

        /** @var LiveChatQuestionDomainObject|null $question */
        $question = $candidates->first(fn (LiveChatQuestionDomainObject $candidate) => in_array(
            $phone,
            explode(',', (string) $candidate->getNotifiedPhones()),
            true,
        ));
        if ($question === null) {
            return null;
        }

        $repliedSince = $this->messageRepository->hasInboundFromPhoneSince(
            $organizerId,
            $phone,
            (string) $question->getLastNotifiedAt(),
            (int) $message->getId(),
        );

        return $repliedSince ? null : $question;
    }

    private function notify(LiveChatQuestionDomainObject $question, bool $isReminder, Carbon $now): bool
    {
        $organizerPhones = $this->teamContactService->list($question->getOrganizerId(), OrganizerTeamContactRole::ORGANIZER)
            ->map(fn (OrganizerTeamContactDomainObject $contact) => $contact->getPhone())
            ->values()
            ->all();
        $supervisors = $this->supervisorService->phones();
        $reference = self::reference($question);
        $eventTitle = $this->eventTitle($question);
        $contact = $this->visitorContactLine($question);

        try {
            $reached = [];
            if ($organizerPhones !== []) {
                $reached = $this->directSendService->sendToMany(
                    $question->getOrganizerId(),
                    $organizerPhones,
                    $isReminder
                        ? __("Reminder :reference, the visitor is still waiting (:event):\n« :question »\n:contact\nReply to this SMS, or write :reference followed by the answer.", [
                            'reference' => $reference, 'event' => $eventTitle, 'question' => $question->getQuestion(), 'contact' => $contact,
                        ], 'fr')
                        : __(":reference, Anicka passes on a question from a visitor (:event):\n« :question »\n:contact\nReply to this SMS, or write :reference followed by the answer. I pass it on to the visitor.", [
                            'reference' => $reference, 'event' => $eventTitle, 'question' => $question->getQuestion(), 'contact' => $contact,
                        ], 'fr'),
                )->sent;
            }

            $toSupervision = $reached === [];
            if ($toSupervision && $supervisors !== []) {
                $this->directSendService->sendToMany(
                    $question->getOrganizerId(),
                    $supervisors,
                    __(":reference, :organizer has no organizer contact who could be reached. Question from a visitor passed on by Anicka (:event):\n« :question »\n:contact\nAnswer with :reference followed by the answer.", [
                        'reference' => $reference, 'organizer' => $this->organizerName($question), 'event' => $eventTitle, 'question' => $question->getQuestion(), 'contact' => $contact,
                    ], 'fr'),
                    isSupervision: true,
                );
            }
        } catch (SmsGatewayNotConfiguredException $exception) {
            $this->logger->warning('Live chat question: no SMS line for this organizer', [
                'live_chat_question_id' => $question->getId(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($reached === [] && $supervisors === []) {
            return false;
        }

        $this->questionRepository->updateFromArray($question->getId(), [
            LiveChatQuestionDomainObjectAbstract::NOTIFIED_PHONES => mb_substr(implode(',', $reached), 0, 255) ?: null,
            LiveChatQuestionDomainObjectAbstract::SENT_TO_SUPERVISION => $toSupervision,
            LiveChatQuestionDomainObjectAbstract::LAST_NOTIFIED_AT => $now->toDateTimeString(),
            ...($isReminder ? [] : [
                LiveChatQuestionDomainObjectAbstract::NEXT_REMINDER_AT => $this->nextReminderAt($now)->toDateTimeString(),
            ]),
        ]);

        return true;
    }

    private function alertSupervision(LiveChatQuestionDomainObject $question): void
    {
        $reference = self::reference($question);
        $organizer = $this->organizerName($question);

        $this->supervisionAlertService->alert(
            organizerId: $question->getOrganizerId(),
            sms: __('PICHA alert: :reference of :organizer has no answer after 2 hours. A reminder was sent. Question: « :question »', [
                'reference' => $reference, 'organizer' => $organizer, 'question' => mb_substr($question->getQuestion(), 0, 160),
            ], 'fr'),
            subject: __('Visitor question :reference without answer', ['reference' => $reference]),
            intro: __('The organizer has not answered this question from a visitor of the chat for 2 hours. A reminder was sent.'),
            details: [
                __('Question') => $reference.' : '.$question->getQuestion(),
                __('Visitor') => $this->visitorContactLine($question),
                __('Organizer') => $organizer.' (#'.$question->getOrganizerId().')',
                __('Event') => $this->eventTitle($question),
            ],
            url: null,
        );
    }

    /**
     * How the organizer can reach the visitor, read from the chat when the SMS leaves (the visitor may have given
     * their details after the question).
     */
    private function visitorContactLine(LiveChatQuestionDomainObject $question): string
    {
        /** @var LiveChatSessionDomainObject|null $session */
        $session = $question->getLiveChatSessionId() === null ? null : $this->sessionRepository->findFirstWhere([
            LiveChatSessionDomainObjectAbstract::ID => $question->getLiveChatSessionId(),
        ]);
        $contact = array_values(array_filter([
            trim((string) $session?->getVisitorPhone()),
            trim((string) $session?->getVisitorEmail()),
        ]));

        return $contact !== []
            ? __('Visitor contact details: :contact', ['contact' => implode(', ', $contact)], 'fr')
            : __('The visitor left no contact details: your answer will only be shown in their chat.', [], 'fr');
    }

    private function eventTitle(LiveChatQuestionDomainObject $question): string
    {
        if ($question->getEventId() === null) {
            return $this->organizerName($question);
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $question->getEventId()]);

        return $event?->getTitle() ?? $this->organizerName($question);
    }

    private function organizerName(LiveChatQuestionDomainObject $question): string
    {
        return (string) ($this->organizerRepository->findFirstWhere(['id' => $question->getOrganizerId()])?->getName() ?? '');
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
