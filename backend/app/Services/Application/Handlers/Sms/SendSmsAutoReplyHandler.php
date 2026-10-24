<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Enums\SmsAutoReplySkipReason;
use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Services\Domain\Sms\SmsAutoReplyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

class SendSmsAutoReplyHandler
{
    public function __construct(
        private readonly SmsAutoReplyService $autoReplyService,
        private readonly SendSmsReplyHandler $replyHandler,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(int $conversationId, int $messageId, ?string $answer, ?string $holdingReply): ?SmsMessageDomainObject
    {
        $check = $this->autoReplyService->check($conversationId, $messageId, $answer, $holdingReply);
        $context = ['sms_conversation_id' => $conversationId, 'sms_message_id' => $messageId];

        if (! $check->isAllowed()) {
            if ($check->skipReason === SmsAutoReplySkipReason::HOLDING_DISABLED && $check->conversation !== null) {
                $this->autoReplyService->keepWaitingForTeam($check->conversation);
            }

            $this->logger->info('SMS assistant: nothing sent automatically', $context + ['reason' => $check->skipReason?->name]);

            return null;
        }

        if ($check->type === SmsAutoReplyType::HOLDING) {
            $this->autoReplyService->keepWaitingForTeam($check->conversation);
        }

        try {
            $sent = $this->replyHandler->handle(
                organizerId: $check->conversation->getOrganizerId(),
                conversationId: $check->conversation->getId(),
                content: $check->content,
                userId: null,
                autoReplyToMessageId: $messageId,
                autoReplyType: $check->type,
            );
        } catch (UniqueConstraintViolationException) {
            $this->logger->info('SMS assistant: this SMS already got its automatic reply', $context);

            return null;
        } catch (ResourceNotFoundException|SmsGatewayNotConfiguredException|SmsRecipientOptedOutException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
            $this->logger->warning('SMS assistant: automatic reply not sent', $context + ['error' => $exception->getMessage()]);

            return null;
        }

        $this->logger->info('SMS assistant: reply sent automatically', $context + ['type' => $check->type->name]);

        return $sent;
    }
}
