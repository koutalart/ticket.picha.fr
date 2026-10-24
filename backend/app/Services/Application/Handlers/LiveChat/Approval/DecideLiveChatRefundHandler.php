<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\LiveChat\Approval;

use HiEvents\DomainObjects\Enums\RefundDecision;
use HiEvents\DomainObjects\Enums\RefundDecisionChannel;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\LiveChatRefundRequestStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\LiveChatRefundHandOffException;
use HiEvents\Exceptions\LiveChatRefundNotEligibleException;
use HiEvents\Exceptions\LiveChatRefundSelectionException;
use HiEvents\Exceptions\RefundAlreadyDecidedException;
use HiEvents\Exceptions\RefundApprovalException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO\RefundDecisionActorDTO;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalLinkService;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalService;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisionAlertService;
use Illuminate\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Approves or refuses a web chat refund request, by SMS from a team number, by the signed link or from the dashboard.
 * An approval runs the Stripe refund through the same handler as a refund from the dashboard.
 */
class DecideLiveChatRefundHandler
{
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly RefundApprovalService $approvalService,
        private readonly RefundApprovalLinkService $linkService,
        private readonly LiveChatRefundService $refundService,
        private readonly RefundOrderHandler $refundOrderHandler,
        private readonly SmsSendService $sendService,
        private readonly PlatformSupervisionAlertService $supervisionAlertService,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly CacheManager $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws RefundApprovalException
     * @throws RefundAlreadyDecidedException
     */
    public function decide(int $requestId, RefundDecision $decision, RefundDecisionActorDTO $actor): LiveChatRefundRequestDomainObject
    {
        $lock = $this->cache->store()->lock('live-chat-refund-request:'.$requestId, self::LOCK_SECONDS);
        if (! $lock->block(10)) {
            throw new RefundApprovalException(__('This refund request is being handled, try again in a moment'));
        }

        try {
            $request = $this->approvalService->decide($requestId, $decision, $actor);
            if ($decision === RefundDecision::APPROVED) {
                $this->execute($request);
            }

            return $this->approvalService->find($requestId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws RefundApprovalException
     * @throws RefundAlreadyDecidedException
     */
    public function decideWithLink(int $requestId, ?string $token, RefundDecision $decision): LiveChatRefundRequestDomainObject
    {
        $request = $this->approvalService->find($requestId);
        if ($request === null || ! $this->linkService->isValid($request, $token)) {
            throw new RefundApprovalException(__('This link is not valid or has expired'));
        }

        if ($request->getApprovalLinkUsedAt() !== null) {
            throw new RefundAlreadyDecidedException($request, __('This link was already used'));
        }

        return $this->decide($requestId, $decision, new RefundDecisionActorDTO(RefundDecisionChannel::LINK));
    }

    /**
     * Handles « OUI R12 » / « NON R12 » received from a number of an organizer team. Returns false when the SMS is not
     * such an answer. Only a team contact (ORGANIZER or COPY) of the organizer of the request may decide.
     */
    public function handleTeamSms(OrganizerSmsSettingDomainObject $settings, SmsMessageDomainObject $message, string $contact): bool
    {
        $parsed = RefundApprovalService::parseSmsDecision($message->getContent());
        if ($parsed === null) {
            return false;
        }

        $phone = SmsPhoneNormalizer::normalize($contact) ?? $contact;
        $reference = RefundApprovalService::reference($parsed->requestId);
        $request = $this->approvalService->find($parsed->requestId);
        $validator = $request !== null ? $this->approvalService->isValidator($request, $phone) : null;

        if ($request === null || $validator === null) {
            if ($request !== null) {
                $this->approvalService->log($request, RefundApprovalService::ACTION_REJECTED_DECISION, new RefundDecisionActorDTO(
                    RefundDecisionChannel::SMS,
                    phone: $phone,
                ), 'number outside the team of the organizer');
            }
            $this->reply($settings, $phone, __('No refund request :reference can be decided from this number.', ['reference' => $reference], 'fr'));

            return true;
        }

        try {
            $request = $this->decide($request->getId(), $parsed->decision, new RefundDecisionActorDTO(
                channel: RefundDecisionChannel::SMS,
                phone: $phone,
                name: $validator->getName(),
            ));
        } catch (RefundAlreadyDecidedException $exception) {
            $this->reply($settings, $phone, __(':reference was already handled, nothing changes.', ['reference' => $reference], 'fr'));

            return true;
        } catch (RefundApprovalException $exception) {
            $this->reply($settings, $phone, $exception->getMessage());

            return true;
        }

        $this->reply($settings, $phone, match ($request->getStatus()) {
            LiveChatRefundRequestStatus::REFUNDED->name => __('Noted, :reference approved: :amount refunded to the customer, who is informed.', [
                'reference' => $reference,
                'amount' => $this->approvalService->amountText($request),
            ], 'fr'),
            LiveChatRefundRequestStatus::REFUSED->name => __('Noted, :reference refused. The customer is informed.', ['reference' => $reference], 'fr'),
            default => __(':reference approved, but the refund could not be done automatically. A human takes over.', ['reference' => $reference], 'fr'),
        });

        return true;
    }

    private function execute(LiveChatRefundRequestDomainObject $request): void
    {
        try {
            $prepared = $this->refundService->prepareApproved($request);
        } catch (LiveChatRefundHandOffException|LiveChatRefundNotEligibleException|LiveChatRefundSelectionException $exception) {
            $reason = $exception instanceof LiveChatRefundNotEligibleException ? $exception->refusal->name : $exception->getMessage();
            $this->handOff($request, LiveChatRefundRequestStatus::HANDED_OFF, $reason);

            return;
        }

        $order = $prepared->eligibility->order;

        try {
            $this->refundOrderHandler->handle(new RefundOrderDTO(
                event_id: $order->getEventId(),
                order_id: $order->getId(),
                amount: $prepared->amount,
                notify_buyer: true,
                cancel_order: false,
                attendee_ids: $prepared->tickets->map(fn ($ticket) => $ticket->attendeeId)->values()->all(),
                refund_source: StripePaymentIntentRefundService::REFUND_SOURCE_LIVE_CHAT,
            ));
        } catch (Throwable $exception) {
            $this->logger->error('Live chat: approved refund failed', [
                'live_chat_refund_request_id' => $request->getId(),
                'error' => $exception->getMessage(),
            ]);
            $this->handOff($request, LiveChatRefundRequestStatus::FAILED, $exception->getMessage());

            return;
        }

        $this->refundService->approvedRefunded($prepared);
        $this->approvalService->log($request, RefundApprovalService::ACTION_REFUNDED, details: $this->approvalService->amountText($request));
    }

    private function handOff(LiveChatRefundRequestDomainObject $request, LiveChatRefundRequestStatus $status, string $reason): void
    {
        $this->refundService->approvedHandedOff($request, $status, $reason);
        $this->approvalService->log(
            $request,
            $status === LiveChatRefundRequestStatus::FAILED ? RefundApprovalService::ACTION_REFUND_FAILED : RefundApprovalService::ACTION_HANDED_OFF,
            details: mb_substr($reason, 0, 255),
        );

        $organizer = $this->organizerRepository->findById($request->getOrganizerId());
        $reference = RefundApprovalService::reference($request);
        $this->supervisionAlertService->alert(
            organizerId: $request->getOrganizerId(),
            sms: __('PICHA alert: refund :reference of :organizer approved but not done automatically (:reason). A human must take over.', [
                'reference' => $reference,
                'organizer' => $organizer->getName(),
                'reason' => mb_substr($reason, 0, 100),
            ], 'fr'),
            subject: __('Refund :reference approved, to finish by hand', ['reference' => $reference]),
            intro: __('This refund request was approved, but the automatic refund could not be done. Finish it from the order.'),
            details: [
                __('Request') => $reference,
                __('Organizer') => $organizer->getName().' (#'.$organizer->getId().')',
                __('Order') => $this->approvalService->order($request)?->getPublicId() ?? '?',
                __('Amount') => $this->approvalService->amountText($request),
                __('Reason') => $reason,
            ],
            url: null,
        );
    }

    private function reply(OrganizerSmsSettingDomainObject $settings, string $phone, string $text): void
    {
        try {
            $this->sendService->send(new SendSmsDTO(
                organizerId: $settings->getOrganizerId(),
                phone: $phone,
                content: $text,
                authorType: SmsMessageAuthorType::AUTOMATIC,
                keepDraft: true,
                replyInConversation: true,
            ));
        } catch (SmsRecipientOptedOutException|SmsGatewayNotConfiguredException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
            $this->logger->warning('Refund decision: confirmation SMS not sent', ['error' => $exception->getMessage()]);
        }
    }
}
