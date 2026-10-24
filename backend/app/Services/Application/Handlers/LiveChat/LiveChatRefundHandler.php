<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\LiveChat;

use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatRefundHandOffException;
use HiEvents\Exceptions\LiveChatRefundNotEligibleException;
use HiEvents\Exceptions\LiveChatRefundSelectionException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundStepDTO;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundService;
use Illuminate\Cache\CacheManager;
use Throwable;

/**
 * Public refund steps of the web chat: order number and e-mail, code, then the tickets to cancel.
 * The request then waits for the organizer team (DecideLiveChatRefundHandler): nothing is refunded before.
 */
class LiveChatRefundHandler
{
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly LiveChatService $liveChatService,
        private readonly LiveChatRefundService $refundService,
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
                return $this->refundService->submit($session, $attendeeIds);
            } catch (LiveChatRefundHandOffException|LiveChatRefundNotEligibleException $exception) {
                return $this->refundService->handOffFromException($session, $exception);
            }
        } finally {
            $lock->release();
        }
    }
}
