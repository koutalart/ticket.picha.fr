<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An automatic SMS whose link goes alone in a second SMS, sent right after the text and only when the text was sent.
 */
class SmsWithLinkSender
{
    public function __construct(
        private readonly SmsSendService $sendService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns the text message: a failure of the link SMS is logged and recorded in the conversation, never thrown.
     *
     * @throws SmsGatewayNotConfiguredException
     * @throws SmsRecipientOptedOutException
     * @throws InvalidSmsPhoneNumberException
     * @throws SmsGatewayException
     */
    public function send(SendSmsDTO $text, ?string $link): SmsMessageDomainObject
    {
        $message = $this->sendService->send($text);

        if ($link === null || trim($link) === '') {
            return $message;
        }

        try {
            $this->sendService->send(self::linkSms($text, $link));
        } catch (Throwable $exception) {
            $this->logger->warning('Automatic SMS: link SMS not sent after the text', [
                'organizer_id' => $text->organizerId,
                'sms_message_id' => $message->getId(),
                'reason' => class_basename($exception),
                'error' => $exception->getMessage(),
            ]);
        }

        return $message;
    }

    public static function linkSms(SendSmsDTO $text, string $link): SendSmsDTO
    {
        return new SendSmsDTO(
            organizerId: $text->organizerId,
            phone: $text->phone,
            content: trim($link),
            authorType: $text->authorType,
            sentByUserId: $text->sentByUserId,
            keepDraft: $text->keepDraft,
            replyInConversation: $text->replyInConversation,
            isSupervision: $text->isSupervision,
        );
    }
}
