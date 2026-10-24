<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\Enums\LiveChatRefundRefusal;
use HiEvents\DomainObjects\Generated\LiveChatRefundRequestDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\LiveChatRefundRequestStatus;
use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatRefundHandOffException;
use HiEvents\Exceptions\LiveChatRefundNotEligibleException;
use HiEvents\Exceptions\LiveChatRefundSelectionException;
use HiEvents\Helper\Currency;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Mail\LiveChat\LiveChatRefundAlertMail;
use HiEvents\Mail\LiveChat\LiveChatRefundCodeMail;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\LiveChatMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatRefundRequestRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalSchedule;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalService;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundCustomerNotifier;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatPreparedRefundDTO;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundStepDTO;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundTicketDTO;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refund from the web chat, for orders with a cancellation protection product.
 * Every check is done here, on the server: the order is identified by its number and its e-mail, the visitor
 * proves they own that e-mail with a code, and the amount is recomputed from the order (ticket prices only).
 * Nothing is refunded until a member of the organizer team approves the request (RefundApprovalService).
 */
class LiveChatRefundService
{
    public const VERIFIED_VALIDITY_MINUTES = 30;

    private const RATE_WINDOW_SECONDS = 3600;

    private const TRANSCRIPT_LENGTH = 60;

    private const REASON_PREFIX = 'Remboursement : ';

    private const MAX_REASON_LENGTH = 255;

    public function __construct(
        private readonly LiveChatService $liveChatService,
        private readonly LiveChatRefundEligibilityService $eligibilityService,
        private readonly LiveChatRefundCodeService $codeService,
        private readonly LiveChatRefundAmountCalculator $calculator,
        private readonly LiveChatRefundRequestRepositoryInterface $requestRepository,
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly LiveChatMessageRepositoryInterface $messageRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly CustomerLocaleResolver $localeResolver,
        private readonly RateLimiter $rateLimiter,
        private readonly Mailer $mailer,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly RefundApprovalService $approvalService,
        private readonly RefundCustomerNotifier $customerNotifier,
    ) {}

    /**
     * @throws LiveChatLimitReachedException
     * @throws Throwable
     */
    public function requestCode(LiveChatSessionDomainObject $session, string $reference, string $email, string $ip): LiveChatRefundStepDTO
    {
        $this->hit('live-chat-refund-requests:'.$this->ipHash($ip), 'services.live_chat.refund_max_requests_per_ip_per_hour', 10);

        $reference = LiveChatRefundEligibilityService::normalizeReference($reference);
        $visitorMessage = $this->liveChatService->record($session, LiveChatAuthorType::VISITOR, $this->text($session, 'I would like a refund for order :reference.', [
            'reference' => $reference,
        ]));

        $order = $this->eligibilityService->findOrder($session->getOrganizerId(), $reference, $email);

        try {
            if ($order === null) {
                throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::ORDER_NOT_FOUND);
            }
            $eligibility = $this->eligibilityService->check($order);
        } catch (LiveChatRefundNotEligibleException $exception) {
            $this->createRequest($session, $order?->getId(), $order?->getEventId(), LiveChatRefundRequestStatus::REJECTED, $ip, [
                LiveChatRefundRequestDomainObjectAbstract::FAILURE_REASON => $exception->refusal->name,
            ]);

            return $this->notEligible($session, $visitorMessage, $exception->refusal);
        }

        $sentToday = $this->requestRepository->countWhere([
            LiveChatRefundRequestDomainObjectAbstract::ORDER_ID => $order->getId(),
            [LiveChatRefundRequestDomainObjectAbstract::CODE_HASH, 'not null', null],
            [LiveChatRefundRequestDomainObjectAbstract::CREATED_AT, '>=', Carbon::now('UTC')->subDay()->toDateTimeString()],
        ]);
        if ($sentToday >= LiveChatRefundCodeService::MAX_SENDS_PER_ORDER_PER_DAY) {
            return $this->handOff($session, null, __('too many codes requested for order :reference', ['reference' => $order->getPublicId()]), collect([$visitorMessage]));
        }

        $this->requestRepository->updateWhere(
            [LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::EXPIRED->name],
            [
                LiveChatRefundRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
                LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::CODE_SENT->name,
            ],
        );

        $code = $this->codeService->generate();
        $this->createRequest($session, $order->getId(), $order->getEventId(), LiveChatRefundRequestStatus::CODE_SENT, $ip, [
            LiveChatRefundRequestDomainObjectAbstract::CODE_HASH => $this->codeService->hash($code),
            LiveChatRefundRequestDomainObjectAbstract::CODE_EXPIRES_AT => $this->codeService->expiresAt()->toDateTimeString(),
            LiveChatRefundRequestDomainObjectAbstract::CURRENCY => $order->getCurrency(),
        ]);

        $this->mailer
            ->to($order->getEmail())
            ->locale($this->locale($session))
            ->send(new LiveChatRefundCodeMail(
                event: $eligibility->event,
                organizer: $this->organizer($session),
                code: $code,
                validityMinutes: LiveChatRefundCodeService::VALIDITY_MINUTES,
            ));

        $agentMessage = $this->liveChatService->record($session, LiveChatAuthorType::AGENT, $this->text(
            $session,
            'I have sent a 6-digit code to the e-mail address of the order. Enter it below to continue, it is valid for :minutes minutes.',
            ['minutes' => LiveChatRefundCodeService::VALIDITY_MINUTES],
        ));

        return new LiveChatRefundStepDTO(
            session: $this->sessionRepository->findById($session->getId()),
            step: LiveChatRefundStepDTO::CODE,
            messages: collect([$visitorMessage, $agentMessage]),
            attemptsLeft: LiveChatRefundCodeService::MAX_ATTEMPTS,
        );
    }

    /**
     * @throws LiveChatLimitReachedException
     * @throws Throwable
     */
    public function verifyCode(LiveChatSessionDomainObject $session, string $code, string $ip): LiveChatRefundStepDTO
    {
        $this->hit('live-chat-refund-codes:'.$this->ipHash($ip), 'services.live_chat.refund_max_code_checks_per_ip_per_hour', 20);

        $request = $this->latestRequest($session, LiveChatRefundRequestStatus::CODE_SENT);
        if ($request === null || $this->codeService->isExpired($request)) {
            if ($request !== null) {
                $this->setStatus($request, LiveChatRefundRequestStatus::EXPIRED);
            }

            return $this->codeError($session, $this->text($session, 'This code has expired. Ask for a new one.'), 0);
        }

        if (! $this->codeService->matches($request, $code)) {
            $attempts = $request->getAttempts() + 1;
            $this->requestRepository->updateFromArray($request->getId(), [
                LiveChatRefundRequestDomainObjectAbstract::ATTEMPTS => $attempts,
                ...($attempts >= LiveChatRefundCodeService::MAX_ATTEMPTS
                    ? [LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::BLOCKED->name]
                    : []),
            ]);

            if ($attempts >= LiveChatRefundCodeService::MAX_ATTEMPTS) {
                return $this->handOff($session, null, __('too many incorrect codes'));
            }

            return $this->codeError($session, $this->text($session, 'This code is not correct. Attempts left: :count.', [
                'count' => $this->codeService->attemptsLeft($attempts),
            ]), $this->codeService->attemptsLeft($attempts));
        }

        $request = $this->requestRepository->updateFromArray($request->getId(), [
            LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::VERIFIED->name,
            LiveChatRefundRequestDomainObjectAbstract::VERIFIED_AT => Carbon::now('UTC')->toDateTimeString(),
            LiveChatRefundRequestDomainObjectAbstract::CODE_HASH => null,
        ]);

        try {
            $eligibility = $this->eligibilityService->check($this->eligibilityService->orderOf($request));
        } catch (LiveChatRefundNotEligibleException $exception) {
            $this->setStatus($request, LiveChatRefundRequestStatus::REJECTED, $exception->refusal->name);

            return $this->notEligible($session, null, $exception->refusal);
        }

        if ($session->getVisitorEmail() === null) {
            $this->sessionRepository->updateFromArray($session->getId(), [
                LiveChatSessionDomainObjectAbstract::VISITOR_EMAIL => mb_strtolower(trim((string) $eligibility->order->getEmail())),
            ]);
        }

        $agentMessage = $this->liveChatService->record($session, LiveChatAuthorType::AGENT, $this->text(
            $session,
            'Thank you, your identity is confirmed. Choose the tickets to cancel.',
        ));

        return new LiveChatRefundStepDTO(
            session: $this->sessionRepository->findById($session->getId()),
            step: LiveChatRefundStepDTO::TICKETS,
            messages: collect([$agentMessage]),
            tickets: $eligibility->coverage->tickets,
            maxTickets: $eligibility->coverage->maxTickets,
            currency: $eligibility->order->getCurrency(),
        );
    }

    /**
     * Checks the request with the tickets chosen by the visitor and puts it in the hands of the organizer team:
     * nothing is refunded before one of them approves it.
     *
     * @param  int[]  $attendeeIds
     *
     * @throws LiveChatRefundSelectionException
     * @throws LiveChatRefundHandOffException
     * @throws LiveChatRefundNotEligibleException
     * @throws Throwable
     */
    public function submit(LiveChatSessionDomainObject $session, array $attendeeIds, ?Carbon $now = null): LiveChatRefundStepDTO
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $request = $this->latestRequest($session, LiveChatRefundRequestStatus::VERIFIED);
        if ($request === null || $this->verificationExpired($request)) {
            throw new LiveChatRefundSelectionException($this->text($session, 'Your check has expired. Ask for a refund again to receive a new code.'));
        }

        $eligibility = $this->eligibilityService->check($this->eligibilityService->orderOf($request));
        $tickets = $this->calculator->select($eligibility->coverage, $attendeeIds);
        $amount = $this->calculator->total($tickets);
        $order = $eligibility->order;

        $maxAmount = (float) $this->config->get('services.live_chat.auto_refund_max_amount', 300);
        if ($maxAmount > 0 && $amount > $maxAmount) {
            $this->markHandedOff($request, $tickets, $amount, 'amount above the automatic refund cap');

            throw new LiveChatRefundHandOffException(__('amount of :amount above the automatic refund cap', [
                'amount' => $this->formatAmount($amount, $order->getCurrency()),
            ]));
        }

        $session = $this->sessionRepository->findById($session->getId());
        $phone = $session->getVisitorPhone() !== null ? SmsPhoneNormalizer::normalize($session->getVisitorPhone()) : null;

        $locked = $this->requestRepository->updateWhere(
            [
                LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::AWAITING_APPROVAL->name,
                LiveChatRefundRequestDomainObjectAbstract::ATTENDEE_IDS => json_encode($this->ids($tickets)),
                LiveChatRefundRequestDomainObjectAbstract::AMOUNT => $amount,
                LiveChatRefundRequestDomainObjectAbstract::CURRENCY => $order->getCurrency(),
                LiveChatRefundRequestDomainObjectAbstract::CUSTOMER_PHONE => $phone,
                LiveChatRefundRequestDomainObjectAbstract::CUSTOMER_EMAIL => $order->getEmail() !== null ? mb_strtolower(trim($order->getEmail())) : null,
                LiveChatRefundRequestDomainObjectAbstract::AWAITING_APPROVAL_AT => $now->toDateTimeString(),
                LiveChatRefundRequestDomainObjectAbstract::NEXT_REMINDER_AT => RefundApprovalSchedule::withinDay($now)->toDateTimeString(),
                LiveChatRefundRequestDomainObjectAbstract::NEXT_CUSTOMER_UPDATE_AT => RefundApprovalSchedule::nextCustomerUpdateAt($now)->toDateTimeString(),
            ],
            [
                LiveChatRefundRequestDomainObjectAbstract::ID => $request->getId(),
                LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::VERIFIED->name,
            ],
        );
        if ($locked !== 1) {
            throw new LiveChatRefundSelectionException($this->text($session, 'Your check has expired. Ask for a refund again to receive a new code.'));
        }

        $request = $this->requestRepository->findById($request->getId());
        $this->recordSelection($session, $tickets);
        $agentMessage = $this->liveChatService->record($session, LiveChatAuthorType::AGENT, $this->text(
            $session,
            'Your refund request :reference for :amount is recorded. The organizer will answer very quickly and I will keep you posted.',
            ['reference' => RefundApprovalService::reference($request), 'amount' => $this->formatAmount($amount, $order->getCurrency())],
        ));

        $this->approvalService->submitted($request, $now);

        return new LiveChatRefundStepDTO(
            session: $this->sessionRepository->findById($session->getId()),
            step: LiveChatRefundStepDTO::PENDING,
            messages: collect([$agentMessage]),
            currency: $order->getCurrency(),
            amount: $amount,
        );
    }

    /**
     * Checks again a request approved by the organizer team, with the guards of an automatic refund (amount cap and
     * daily cap). Beyond them, or when the order changed, a human takes over.
     *
     * @throws LiveChatRefundSelectionException
     * @throws LiveChatRefundHandOffException
     * @throws LiveChatRefundNotEligibleException
     */
    public function prepareApproved(LiveChatRefundRequestDomainObject $request): LiveChatPreparedRefundDTO
    {
        if ($request->getStatus() !== LiveChatRefundRequestStatus::PROCESSING->name) {
            throw new LiveChatRefundSelectionException(__('This refund request was already handled'));
        }

        $eligibility = $this->eligibilityService->check($this->eligibilityService->orderOf($request));
        $tickets = $this->calculator->select($eligibility->coverage, array_map('intval', (array) $request->getAttendeeIds()));
        $amount = $this->calculator->total($tickets);

        $maxAmount = (float) $this->config->get('services.live_chat.auto_refund_max_amount', 300);
        if ($maxAmount > 0 && $amount > $maxAmount) {
            throw new LiveChatRefundHandOffException(__('amount of :amount above the automatic refund cap', [
                'amount' => $this->formatAmount($amount, $eligibility->order->getCurrency()),
            ]));
        }

        $maxPerDay = (int) $this->config->get('services.live_chat.auto_refund_max_per_organizer_per_day', 10);
        if ($maxPerDay > 0 && $this->refundsInLastDay($request->getOrganizerId(), $request->getId()) >= $maxPerDay) {
            throw new LiveChatRefundHandOffException(__('daily cap of automatic refunds reached'));
        }

        return new LiveChatPreparedRefundDTO(
            request: $request,
            eligibility: $eligibility,
            tickets: $tickets,
            amount: $amount,
        );
    }

    /**
     * The refund of an approved request went through: the customer is told, the organizer and the platform get the alert.
     */
    public function approvedRefunded(LiveChatPreparedRefundDTO $prepared): void
    {
        $request = $prepared->request;
        $now = Carbon::now('UTC')->toDateTimeString();

        $this->requestRepository->updateFromArray($request->getId(), [
            LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::REFUNDED->name,
            LiveChatRefundRequestDomainObjectAbstract::REFUNDED_AT => $now,
        ]);

        if ($request->getLiveChatSessionId() !== null) {
            $this->sessionRepository->updateWhere(
                [LiveChatSessionDomainObjectAbstract::REFUNDED_AT => $now],
                [LiveChatSessionDomainObjectAbstract::ID => $request->getLiveChatSessionId()],
            );
        }

        $this->customerNotifier->notify($request, __(
            'Good news, your refund request :reference is approved: :amount will be refunded to the card used for the order, usually within 5 to 10 days depending on your bank. The cancelled tickets are no longer valid.',
            ['reference' => RefundApprovalService::reference($request), 'amount' => $this->formatAmount($prepared->amount, $prepared->eligibility->order->getCurrency())],
            $this->customerNotifier->locale($request),
        ));

        $this->sendAlert($request->getOrganizerId(), $request->getLiveChatSessionId(), $prepared);
    }

    /**
     * An approved request that cannot be refunded automatically (caps, order changed, Stripe error): a human takes over.
     */
    public function approvedHandedOff(LiveChatRefundRequestDomainObject $request, LiveChatRefundRequestStatus $status, string $reason): void
    {
        $this->setStatus($request, $status, mb_substr($reason, 0, self::MAX_REASON_LENGTH));

        $this->customerNotifier->notify($request, __(
            'Your refund request :reference is approved. The organizer is finishing it personally and will get back to you.',
            ['reference' => RefundApprovalService::reference($request)],
            $this->customerNotifier->locale($request),
        ));
    }

    /**
     * @throws Throwable
     */
    public function recordSelection(LiveChatSessionDomainObject $session, Collection $tickets): void
    {
        $this->liveChatService->record($session, LiveChatAuthorType::VISITOR, $this->text($session, 'Cancel these tickets: :tickets.', [
            'tickets' => $tickets->map(fn (LiveChatRefundTicketDTO $ticket) => trim($ticket->name.' ('.$ticket->category.')'))->implode(', '),
        ]));
    }

    /**
     * @throws Throwable
     */
    public function handOffFromException(LiveChatSessionDomainObject $session, LiveChatRefundHandOffException|LiveChatRefundNotEligibleException $exception): LiveChatRefundStepDTO
    {
        if ($exception instanceof LiveChatRefundNotEligibleException) {
            $request = $this->latestRequest($session, LiveChatRefundRequestStatus::VERIFIED);
            if ($request !== null) {
                $this->setStatus($request, LiveChatRefundRequestStatus::REJECTED, $exception->refusal->name);
            }

            return $this->notEligible($session, null, $exception->refusal);
        }

        return $this->handOff($session, null, $exception->getMessage());
    }

    /**
     * The visitor gets the same answer whatever the reason, so that it never tells whether the order exists.
     *
     * @throws Throwable
     */
    private function notEligible(LiveChatSessionDomainObject $session, ?LiveChatMessageDomainObject $visitorMessage, LiveChatRefundRefusal $refusal): LiveChatRefundStepDTO
    {
        return $this->handOff(
            $session,
            $this->text($session, 'I cannot handle this request automatically: either these details do not match an order, or the order is not eligible for an automatic refund (no cancellation protection, or too close to the event).'),
            $refusal->label(),
            $visitorMessage !== null ? collect([$visitorMessage]) : collect(),
        );
    }

    /**
     * Same hand-over as any question the assistant cannot answer: the organizer gets the transcript,
     * by SMS conversation or by e-mail, as soon as the contact details of the visitor are known.
     *
     * @param  Collection<int, LiveChatMessageDomainObject>|null  $before
     *
     * @throws Throwable
     */
    private function handOff(LiveChatSessionDomainObject $session, ?string $intro, string $reason, ?Collection $before = null): LiveChatRefundStepDTO
    {
        $session = $this->sessionRepository->findById($session->getId());
        $contactKnown = $session->getVisitorPhone() !== null || $session->getVisitorEmail() !== null;
        $intro ??= $this->text($session, 'I cannot finish this refund automatically.');
        $next = $contactKnown
            ? $this->text($session, 'I have passed your request to the organizer, who will get back to you.')
            : $this->text($session, 'Leave your contact details and the organizer will look into it.');

        $agentMessage = $this->liveChatService->record($session, LiveChatAuthorType::AGENT, $intro.' '.$next, [
            LiveChatSessionDomainObjectAbstract::NEEDS_FOLLOW_UP => true,
            LiveChatSessionDomainObjectAbstract::FOLLOW_UP_REASON => mb_substr(self::REASON_PREFIX.$reason, 0, self::MAX_REASON_LENGTH),
        ], needsFollowUp: true);

        if ($contactKnown) {
            $session = $this->liveChatService->handOffKnownContact($session);
        }

        return new LiveChatRefundStepDTO(
            session: $this->sessionRepository->findById($session->getId()),
            step: LiveChatRefundStepDTO::UNAVAILABLE,
            messages: ($before ?? collect())->push($agentMessage)->values(),
            askContact: ! $contactKnown,
        );
    }

    private function codeError(LiveChatSessionDomainObject $session, string $error, int $attemptsLeft): LiveChatRefundStepDTO
    {
        return new LiveChatRefundStepDTO(
            session: $session,
            step: LiveChatRefundStepDTO::CODE,
            messages: collect(),
            error: $error,
            attemptsLeft: $attemptsLeft,
        );
    }

    private function sendAlert(int $organizerId, ?int $sessionId, LiveChatPreparedRefundDTO $prepared): void
    {
        $organizer = $this->organizerRepository->findById($organizerId);
        $recipients = array_values(array_unique(array_filter([
            trim((string) $organizer->getEmail()),
            trim((string) $this->config->get('services.sms_watchdog.alert_email')),
        ])));

        $transcript = $sessionId !== null ? $this->messageRepository->findLatestForSession($sessionId, self::TRANSCRIPT_LENGTH) : collect();

        foreach ($recipients as $recipient) {
            try {
                $this->mailer
                    ->to($recipient)
                    ->locale(CustomerLocale::get())
                    ->send(new LiveChatRefundAlertMail(
                        organizer: $organizer,
                        event: $prepared->eligibility->event,
                        order: $prepared->eligibility->order,
                        tickets: $prepared->tickets,
                        amount: $prepared->amount,
                        transcript: $transcript,
                    ));
            } catch (Throwable $exception) {
                $this->logger->error('Live chat: refund alert not sent', [
                    'live_chat_session_id' => $sessionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, LiveChatRefundTicketDTO>  $tickets
     */
    private function markHandedOff(LiveChatRefundRequestDomainObject $request, Collection $tickets, float $amount, string $reason): void
    {
        $this->requestRepository->updateFromArray($request->getId(), [
            LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::HANDED_OFF->name,
            LiveChatRefundRequestDomainObjectAbstract::ATTENDEE_IDS => $this->ids($tickets),
            LiveChatRefundRequestDomainObjectAbstract::AMOUNT => $amount,
            LiveChatRefundRequestDomainObjectAbstract::FAILURE_REASON => $reason,
        ]);
    }

    private function refundsInLastDay(int $organizerId, ?int $exceptRequestId = null): int
    {
        return $this->requestRepository->countWhere([
            ...($exceptRequestId !== null ? [[LiveChatRefundRequestDomainObjectAbstract::ID, '!=', $exceptRequestId]] : []),
            LiveChatRefundRequestDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            [LiveChatRefundRequestDomainObjectAbstract::STATUS, 'in', [
                LiveChatRefundRequestStatus::REFUNDED->name,
                LiveChatRefundRequestStatus::PROCESSING->name,
            ]],
            [LiveChatRefundRequestDomainObjectAbstract::UPDATED_AT, '>=', Carbon::now('UTC')->subDay()->toDateTimeString()],
        ]);
    }

    private function verificationExpired(LiveChatRefundRequestDomainObject $request): bool
    {
        $verifiedAt = $request->getVerifiedAt();

        return $verifiedAt === null
            || Carbon::now('UTC')->greaterThan(Carbon::parse($verifiedAt, 'UTC')->addMinutes(self::VERIFIED_VALIDITY_MINUTES));
    }

    private function latestRequest(LiveChatSessionDomainObject $session, LiveChatRefundRequestStatus $status): ?LiveChatRefundRequestDomainObject
    {
        return $this->requestRepository->findWhere(
            [
                LiveChatRefundRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
                LiveChatRefundRequestDomainObjectAbstract::STATUS => $status->name,
            ],
            orderAndDirections: [new OrderAndDirection(LiveChatRefundRequestDomainObjectAbstract::ID, OrderAndDirection::DIRECTION_DESC)],
        )->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRequest(
        LiveChatSessionDomainObject $session,
        ?int $orderId,
        ?int $eventId,
        LiveChatRefundRequestStatus $status,
        string $ip,
        array $attributes = [],
    ): LiveChatRefundRequestDomainObject {
        return $this->requestRepository->create([
            LiveChatRefundRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
            LiveChatRefundRequestDomainObjectAbstract::ORGANIZER_ID => $session->getOrganizerId(),
            LiveChatRefundRequestDomainObjectAbstract::EVENT_ID => $eventId,
            LiveChatRefundRequestDomainObjectAbstract::ORDER_ID => $orderId,
            LiveChatRefundRequestDomainObjectAbstract::STATUS => $status->name,
            LiveChatRefundRequestDomainObjectAbstract::IP_HASH => $this->ipHash($ip),
            ...$attributes,
        ]);
    }

    private function setStatus(LiveChatRefundRequestDomainObject $request, LiveChatRefundRequestStatus $status, ?string $reason = null): void
    {
        $this->requestRepository->updateFromArray($request->getId(), [
            LiveChatRefundRequestDomainObjectAbstract::STATUS => $status->name,
            ...($reason !== null ? [LiveChatRefundRequestDomainObjectAbstract::FAILURE_REASON => $reason] : []),
        ]);
    }

    /**
     * @param  Collection<int, LiveChatRefundTicketDTO>  $tickets
     * @return int[]
     */
    private function ids(Collection $tickets): array
    {
        return $tickets->map(fn (LiveChatRefundTicketDTO $ticket) => $ticket->attendeeId)->values()->all();
    }

    /**
     * @throws LiveChatLimitReachedException
     */
    private function hit(string $key, string $configKey, int $default): void
    {
        $max = (int) $this->config->get($configKey, $default);
        if ($max <= 0) {
            return;
        }

        if ($this->rateLimiter->tooManyAttempts($key, $max)) {
            throw new LiveChatLimitReachedException(
                __('Too many messages for now. Leave your contact details or try again later.'),
                LiveChatLimitReachedException::IP,
            );
        }

        $this->rateLimiter->hit($key, self::RATE_WINDOW_SECONDS);
    }

    private function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) $this->config->get('app.key'));
    }

    private function organizer(LiveChatSessionDomainObject $session): OrganizerDomainObject
    {
        return $this->organizerRepository->findById($session->getOrganizerId());
    }

    private function locale(LiveChatSessionDomainObject $session): string
    {
        return $this->localeResolver->forOrganizerId($session->getOrganizerId());
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function text(LiveChatSessionDomainObject $session, string $key, array $replace = []): string
    {
        return __($key, $replace, $this->locale($session));
    }

    private function formatAmount(float $amount, string $currency): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', Currency::format($amount, $currency, 'fr_FR'));
    }
}
