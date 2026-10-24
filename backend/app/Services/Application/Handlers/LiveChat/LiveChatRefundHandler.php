<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\LiveChat;

use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatRefundHandOffException;
use HiEvents\Exceptions\LiveChatRefundNotEligibleException;
use HiEvents\Exceptions\LiveChatRefundSelectionException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundStepDTO;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use Illuminate\Cache\CacheManager;
use Throwable;

/**
 * Public refund steps of the web chat: order number and e-mail, code, then the tickets to cancel.
 * The refund itself goes through the same handler as a refund from the dashboard.
 */
class LiveChatRefundHandler
{
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly LiveChatService $liveChatService,
        private readonly LiveChatRefundService $refundService,
        private readonly RefundOrderHandler $refundOrderHandler,
        private readonly CacheManager $cache,
    ) {}

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatLimitReachedException
     * @throws Throwable
     */
    public function requestCode(string $sessionId, string $reference, string $email, string $ip): LiveChatRefundStepDTO
    {
        return $this->refundService->requestCode($this->liveChatService->findSession($sessionId), $reference, $email, $ip);
    }

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatLimitReachedException
     * @throws Throwable
     */
    public function verifyCode(string $sessionId, string $code, string $ip): LiveChatRefundStepDTO
    {
        return $this->refundService->verifyCode($this->liveChatService->findSession($sessionId), $code, $ip);
    }

    /**
     * @param  int[]  $attendeeIds
     *
     * @throws LiveChatUnavailableException
     * @throws LiveChatRefundSelectionException
     * @throws Throwable
     */
    public function confirm(string $sessionId, array $attendeeIds): LiveChatRefundStepDTO
    {
        $session = $this->liveChatService->findSession($sessionId);
        $lock = $this->cache->lock('live-chat-refund-session:'.$session->getId(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new LiveChatRefundSelectionException(__('Your request is already being processed.'));
        }

        try {
            try {
                $prepared = $this->refundService->prepare($session, $attendeeIds);
            } catch (LiveChatRefundHandOffException|LiveChatRefundNotEligibleException $exception) {
                return $this->refundService->handOffFromException($session, $exception);
            }

            $this->refundService->recordSelection($session, $prepared->tickets);
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
                return $this->refundService->refundFailed($session, $prepared, $exception);
            }

            return $this->refundService->refunded($session, $prepared);
        } finally {
            $lock->release();
        }
    }
}
