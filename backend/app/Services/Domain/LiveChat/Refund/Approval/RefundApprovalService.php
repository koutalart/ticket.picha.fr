<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\Enums\RefundDecision;
use HiEvents\DomainObjects\Enums\RefundDecisionChannel;
use HiEvents\DomainObjects\Generated\LiveChatRefundRequestDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatRefundRequestLogDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\DomainObjects\Status\LiveChatRefundRequestStatus;
use HiEvents\Exceptions\RefundAlreadyDecidedException;
use HiEvents\Exceptions\RefundApprovalException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\LiveChatRefundRequestLogRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatRefundRequestRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO\RefundApprovalRunResultDTO;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO\RefundDecisionActorDTO;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO\RefundSmsDecisionDTO;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisionAlertService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisorService;
use HiEvents\Services\Domain\Sms\Team\SmsDirectSendService;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Refunds from the web chat wait for a member of the organizer team (ORGANIZER or COPY) to approve them, by SMS
 * (« OUI R12 » or « NON R12 » from a team number), by a signed link or from the dashboard. The first decision wins.
 * Meanwhile the customer hears from us at least every 12 hours, the validators get reminders, and the platform
 * supervision is alerted after 24 hours without decision. Every step is logged with who did it and how.
 */
class RefundApprovalService
{
    private const LOCK_SECONDS = 55;

    private const BATCH = 100;

    public const ACTION_SUBMITTED = 'SUBMITTED';

    public const ACTION_VALIDATORS_NOTIFIED = 'VALIDATORS_NOTIFIED';

    public const ACTION_CUSTOMER_NOTIFIED = 'CUSTOMER_NOTIFIED';

    public const ACTION_ESCALATED = 'ESCALATED';

    public const ACTION_APPROVED = 'APPROVED';

    public const ACTION_REFUSED = 'REFUSED';

    public const ACTION_REJECTED_DECISION = 'REJECTED_DECISION';

    public const ACTION_REFUNDED = 'REFUNDED';

    public const ACTION_REFUND_FAILED = 'REFUND_FAILED';

    public const ACTION_HANDED_OFF = 'HANDED_OFF';

    public function __construct(
        private readonly LiveChatRefundRequestRepositoryInterface $requestRepository,
        private readonly LiveChatRefundRequestLogRepositoryInterface $logRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly PlatformSupervisorService $supervisorService,
        private readonly PlatformSupervisionAlertService $supervisionAlertService,
        private readonly SmsDirectSendService $directSendService,
        private readonly RefundApprovalLinkService $linkService,
        private readonly RefundCustomerNotifier $customerNotifier,
        private readonly RefundCustomerUpdateGenerator $updateGenerator,
        private readonly CacheManager $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public static function reference(LiveChatRefundRequestDomainObject|int $request): string
    {
        return 'R'.($request instanceof LiveChatRefundRequestDomainObject ? $request->getId() : $request);
    }

    /**
     * « OUI R12 », « non r 12 », « Oui R12 ! »: the answer of a validator to a refund request.
     */
    public static function parseSmsDecision(string $content): ?RefundSmsDecisionDTO
    {
        if (preg_match('/^\s*(oui|non)\s*r\s*(\d{1,9})\s*[.!]*\s*$/iu', $content, $matches) !== 1) {
            return null;
        }

        return new RefundSmsDecisionDTO(
            decision: mb_strtolower($matches[1]) === 'oui' ? RefundDecision::APPROVED : RefundDecision::REFUSED,
            requestId: (int) $matches[2],
        );
    }

    public function find(int $requestId): ?LiveChatRefundRequestDomainObject
    {
        return $this->requestRepository->findFirstWhere([LiveChatRefundRequestDomainObjectAbstract::ID => $requestId]);
    }

    public function isValidator(LiveChatRefundRequestDomainObject $request, string $phone): ?OrganizerTeamContactDomainObject
    {
        return $this->teamContactService->find($request->getOrganizerId(), $phone);
    }

    /**
     * Right after the customer asked: acknowledgement to the customer, and the request to the validators when it is
     * daytime in Mayotte (otherwise at 8:00, by the scheduled run).
     */
    public function submitted(LiveChatRefundRequestDomainObject $request, ?Carbon $now = null): void
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $this->log($request, self::ACTION_SUBMITTED, details: $this->amountText($request));

        $locale = $this->customerNotifier->locale($request);
        $channel = $this->customerNotifier->notify($request, __(
            'Your refund request :reference for :amount is recorded. The organizer will answer very quickly and I will keep you posted.',
            ['reference' => self::reference($request), 'amount' => $this->amountText($request)],
            $locale,
        ), alsoInChat: false);
        $this->log($request, self::ACTION_CUSTOMER_NOTIFIED, details: 'acknowledgement'.($channel !== null ? ' by '.$channel : ', unreachable'));

        if ($request->getNextReminderAt() !== null && Carbon::parse($request->getNextReminderAt(), 'UTC')->lte($now)) {
            $this->remindValidators($this->find($request->getId()), $now);
        }
    }

    /**
     * Records the first decision on the request. Any later decision, by any channel, is refused.
     *
     * @throws RefundApprovalException
     * @throws RefundAlreadyDecidedException
     */
    public function decide(int $requestId, RefundDecision $decision, RefundDecisionActorDTO $actor, ?Carbon $now = null): LiveChatRefundRequestDomainObject
    {
        $request = $this->find($requestId);
        if ($request === null) {
            throw new RefundApprovalException(__('This refund request does not exist'));
        }

        $now = ($now ?? Carbon::now('UTC'))->copy()->utc()->toDateTimeString();
        $claimed = $this->requestRepository->updateWhere(
            [
                LiveChatRefundRequestDomainObjectAbstract::STATUS => $decision === RefundDecision::APPROVED
                    ? LiveChatRefundRequestStatus::PROCESSING->name
                    : LiveChatRefundRequestStatus::REFUSED->name,
                LiveChatRefundRequestDomainObjectAbstract::DECISION => $decision->name,
                LiveChatRefundRequestDomainObjectAbstract::DECIDED_AT => $now,
                LiveChatRefundRequestDomainObjectAbstract::DECIDED_BY_CHANNEL => $actor->channel->name,
                LiveChatRefundRequestDomainObjectAbstract::DECIDED_BY_PHONE => $actor->phone,
                LiveChatRefundRequestDomainObjectAbstract::DECIDED_BY_USER_ID => $actor->userId,
                LiveChatRefundRequestDomainObjectAbstract::DECIDED_BY_NAME => $actor->name !== null ? mb_substr($actor->name, 0, 150) : null,
                LiveChatRefundRequestDomainObjectAbstract::NEXT_REMINDER_AT => null,
                LiveChatRefundRequestDomainObjectAbstract::NEXT_CUSTOMER_UPDATE_AT => null,
                ...($actor->channel === RefundDecisionChannel::LINK ? [LiveChatRefundRequestDomainObjectAbstract::APPROVAL_LINK_USED_AT => $now] : []),
            ],
            [
                LiveChatRefundRequestDomainObjectAbstract::ID => $requestId,
                LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::AWAITING_APPROVAL->name,
            ],
        );

        if ($claimed !== 1) {
            $this->log($request, self::ACTION_REJECTED_DECISION, $actor, $decision->name.' refused: request already '.$request->getStatus());

            throw new RefundAlreadyDecidedException($request, __('This refund request was already handled'));
        }

        $request = $this->find($requestId);
        $this->log($request, $decision === RefundDecision::APPROVED ? self::ACTION_APPROVED : self::ACTION_REFUSED, $actor);

        if ($decision === RefundDecision::REFUSED) {
            $this->customerNotifier->notify($request, __(
                'The organizer has looked at your refund request :reference carefully and cannot accept it. Thank you for your understanding.',
                ['reference' => self::reference($request)],
                $this->customerNotifier->locale($request),
            ));
        }

        return $request;
    }

    public function log(
        LiveChatRefundRequestDomainObject $request,
        string $action,
        ?RefundDecisionActorDTO $actor = null,
        ?string $details = null,
    ): void {
        $this->logRepository->create([
            LiveChatRefundRequestLogDomainObjectAbstract::LIVE_CHAT_REFUND_REQUEST_ID => $request->getId(),
            LiveChatRefundRequestLogDomainObjectAbstract::ACTION => $action,
            LiveChatRefundRequestLogDomainObjectAbstract::CHANNEL => $actor?->channel->name,
            LiveChatRefundRequestLogDomainObjectAbstract::ACTOR_PHONE => $actor?->phone,
            LiveChatRefundRequestLogDomainObjectAbstract::ACTOR_USER_ID => $actor?->userId,
            LiveChatRefundRequestLogDomainObjectAbstract::ACTOR_NAME => $actor?->name !== null ? mb_substr($actor->name, 0, 150) : null,
            LiveChatRefundRequestLogDomainObjectAbstract::DETAILS => $details,
        ]);

        $this->logger->info('Live chat refund request', [
            'live_chat_refund_request_id' => $request->getId(),
            'action' => $action,
            'channel' => $actor?->channel->name,
            'actor_user_id' => $actor?->userId,
        ]);
    }

    /**
     * Reminders to the validators, news to the customer and the 24 hour alert that are due. Each request is claimed
     * before anything is sent, so a run that overlaps another one sends nothing twice.
     */
    public function processDue(?Carbon $now = null): RefundApprovalRunResultDTO
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $lock = $this->cache->store()->lock('live-chat-refund-approvals', self::LOCK_SECONDS);
        if (! $lock->get()) {
            return new RefundApprovalRunResultDTO;
        }

        try {
            $reminders = 0;
            foreach ($this->due(LiveChatRefundRequestDomainObjectAbstract::NEXT_REMINDER_AT, $now) as $request) {
                $reminders += $this->remindValidators($request, $now) ? 1 : 0;
            }

            $updates = 0;
            foreach ($this->due(LiveChatRefundRequestDomainObjectAbstract::NEXT_CUSTOMER_UPDATE_AT, $now) as $request) {
                $updates += $this->updateCustomer($request, $now) ? 1 : 0;
            }

            $escalations = 0;
            foreach ($this->requestRepository->findWhere([
                LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::AWAITING_APPROVAL->name,
                [LiveChatRefundRequestDomainObjectAbstract::ESCALATED_AT, 'null', null],
                [LiveChatRefundRequestDomainObjectAbstract::AWAITING_APPROVAL_AT, '<=', $now->copy()->subHours(RefundApprovalSchedule::ESCALATION_HOURS)->toDateTimeString()],
            ])->take(self::BATCH) as $request) {
                $escalations += $this->escalate($request, $now) ? 1 : 0;
            }

            return new RefundApprovalRunResultDTO($reminders, $updates, $escalations);
        } finally {
            $lock->release();
        }
    }

    public function remindValidators(LiveChatRefundRequestDomainObject $request, Carbon $now): bool
    {
        $sent = $request->getRemindersCount() + 1;
        if (! $this->claim($request, LiveChatRefundRequestDomainObjectAbstract::NEXT_REMINDER_AT, [
            LiveChatRefundRequestDomainObjectAbstract::NEXT_REMINDER_AT => RefundApprovalSchedule::nextReminderAt($now, $sent)->toDateTimeString(),
            LiveChatRefundRequestDomainObjectAbstract::REMINDERS_COUNT => $sent,
            LiveChatRefundRequestDomainObjectAbstract::LAST_REMINDER_AT => $now->toDateTimeString(),
        ])) {
            return false;
        }

        $phones = $this->validatorPhones($request);
        $isSupervision = $phones === [];
        if ($isSupervision) {
            $phones = $this->supervisorService->phones();
        }

        try {
            $result = $this->directSendService->sendToMany(
                $request->getOrganizerId(),
                $phones,
                $this->validatorText($request, $sent > 1, $now),
                isSupervision: $isSupervision,
            );
            $this->log($request, self::ACTION_VALIDATORS_NOTIFIED, details: sprintf(
                '%s %d, sent %d, opted out %d, failed %d%s',
                $sent > 1 ? 'reminder' : 'request',
                $sent,
                count($result->sent),
                count($result->optedOut),
                count($result->failed),
                $isSupervision ? ', no team contact: sent to the supervision' : '',
            ));
        } catch (SmsGatewayNotConfiguredException $exception) {
            $this->log($request, self::ACTION_VALIDATORS_NOTIFIED, details: 'not sent: '.$exception->getMessage());
        }

        return true;
    }

    public function updateCustomer(LiveChatRefundRequestDomainObject $request, Carbon $now): bool
    {
        $number = $request->getCustomerUpdatesCount() + 1;
        if (! $this->claim($request, LiveChatRefundRequestDomainObjectAbstract::NEXT_CUSTOMER_UPDATE_AT, [
            LiveChatRefundRequestDomainObjectAbstract::NEXT_CUSTOMER_UPDATE_AT => RefundApprovalSchedule::nextCustomerUpdateAt($now)->toDateTimeString(),
            LiveChatRefundRequestDomainObjectAbstract::CUSTOMER_UPDATES_COUNT => $number,
            LiveChatRefundRequestDomainObjectAbstract::LAST_CUSTOMER_UPDATE_AT => $now->toDateTimeString(),
        ])) {
            return false;
        }

        $organizer = $this->organizerRepository->findById($request->getOrganizerId());
        $text = $this->updateGenerator->generate(
            $organizer->getName(),
            self::reference($request),
            $number,
            $this->customerNotifier->locale($request),
        );

        $channel = $this->customerNotifier->notify($request, $text);
        $this->log($request, self::ACTION_CUSTOMER_NOTIFIED, details: 'update '.$number.($channel !== null ? ' by '.$channel : ', unreachable'));

        return true;
    }

    public function escalate(LiveChatRefundRequestDomainObject $request, Carbon $now): bool
    {
        $claimed = $this->requestRepository->updateWhere(
            [LiveChatRefundRequestDomainObjectAbstract::ESCALATED_AT => $now->toDateTimeString()],
            [
                LiveChatRefundRequestDomainObjectAbstract::ID => $request->getId(),
                LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::AWAITING_APPROVAL->name,
                [LiveChatRefundRequestDomainObjectAbstract::ESCALATED_AT, 'null', null],
            ],
        );
        if ($claimed !== 1) {
            return false;
        }

        $organizer = $this->organizerRepository->findById($request->getOrganizerId());
        $order = $this->order($request);
        $url = $this->linkService->url($request->getId(), $now);
        $reference = self::reference($request);

        $this->supervisionAlertService->alert(
            organizerId: $request->getOrganizerId(),
            sms: __("PICHA alert: refund :reference of :organizer has no decision after 24 hours.\nCustomer: :customer, order :order, :amount.\n:url", [
                'reference' => $reference,
                'organizer' => $organizer->getName(),
                'customer' => $this->customerName($order),
                'order' => $order?->getPublicId() ?? '?',
                'amount' => $this->amountText($request),
                'url' => $url,
            ], 'fr'),
            subject: __('Refund :reference without decision for 24 hours', ['reference' => $reference]),
            intro: __('Nobody of the team of :organizer has approved or refused this refund request for 24 hours.', ['organizer' => $organizer->getName()]),
            details: [
                __('Request') => $reference,
                __('Organizer') => $organizer->getName().' (#'.$organizer->getId().')',
                __('Customer') => $this->customerName($order),
                __('Order') => $order?->getPublicId() ?? '?',
                __('Amount') => $this->amountText($request),
                __('Reminders sent') => (string) $request->getRemindersCount(),
            ],
            url: $url,
        );
        $this->log($request, self::ACTION_ESCALATED);

        return true;
    }

    public function validatorText(LiveChatRefundRequestDomainObject $request, bool $isReminder, ?Carbon $now = null): string
    {
        $reference = self::reference($request);
        $order = $this->order($request);

        $lines = [
            $isReminder
                ? __('Reminder: refund :reference to approve', ['reference' => $reference], 'fr')
                : __('Refund :reference to approve', ['reference' => $reference], 'fr'),
            __('Customer: :name', ['name' => $this->customerName($order)], 'fr'),
            __('Order: :order', ['order' => $order?->getPublicId() ?? '?'], 'fr'),
            __('Amount: :amount', ['amount' => $this->amountText($request)], 'fr'),
        ];

        $reason = trim((string) $request->getReason());
        if ($reason !== '') {
            $lines[] = __('Reason: :reason', ['reason' => mb_substr($reason, 0, 120)], 'fr');
        }

        $lines[] = __('Answer OUI :reference or NON :reference', ['reference' => $reference], 'fr');
        $lines[] = $this->linkService->url($request->getId(), $now);

        return implode("\n", $lines);
    }

    public function amountText(LiveChatRefundRequestDomainObject $request): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', Currency::format((float) $request->getAmount(), $request->getCurrency() ?: 'EUR', 'fr_FR'));
    }

    public function customerName(?OrderDomainObject $order): string
    {
        $name = trim(($order?->getFirstName() ?? '').' '.($order?->getLastName() ?? ''));

        return $name !== '' ? $name : ($order?->getEmail() ?? '?');
    }

    public function order(LiveChatRefundRequestDomainObject $request): ?OrderDomainObject
    {
        return $request->getOrderId() === null
            ? null
            : $this->orderRepository->findFirstWhere([OrderDomainObjectAbstract::ID => $request->getOrderId()]);
    }

    /**
     * @return array<int, string>
     */
    private function validatorPhones(LiveChatRefundRequestDomainObject $request): array
    {
        return $this->teamContactService->list($request->getOrganizerId())
            ->filter(fn (OrganizerTeamContactDomainObject $contact) => in_array($contact->getRole(), [
                OrganizerTeamContactRole::ORGANIZER->name,
                OrganizerTeamContactRole::COPY->name,
            ], true))
            ->map(fn (OrganizerTeamContactDomainObject $contact) => $contact->getPhone())
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, LiveChatRefundRequestDomainObject>
     */
    private function due(string $column, Carbon $now): Collection
    {
        return $this->requestRepository->findWhere([
            LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::AWAITING_APPROVAL->name,
            [$column, '<=', $now->toDateTimeString()],
        ])->take(self::BATCH);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function claim(LiveChatRefundRequestDomainObject $request, string $column, array $attributes): bool
    {
        $current = $request->toArray()[$column] ?? null;

        return $this->requestRepository->updateWhere($attributes, [
            LiveChatRefundRequestDomainObjectAbstract::ID => $request->getId(),
            LiveChatRefundRequestDomainObjectAbstract::STATUS => LiveChatRefundRequestStatus::AWAITING_APPROVAL->name,
            $current === null ? [$column, 'null', null] : [$column, '=', $current],
        ]) === 1;
    }
}
