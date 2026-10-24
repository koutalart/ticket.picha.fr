<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Team;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Domain\Sms\Team\DTO\SmsBulkSendResultDTO;
use Psr\Log\LoggerInterface;

/**
 * Sends the same SMS from the organizer's line (its own or the shared one) to a few numbers. A number without
 * conversation gets one for the organizer, and a number that replied STOP to the organizer is skipped.
 */
class SmsDirectSendService
{
    public function __construct(
        private readonly SmsSendService $sendService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<int, string>  $phones
     *
     * @throws SmsGatewayNotConfiguredException
     */
    public function sendToMany(
        int $organizerId,
        array $phones,
        string $content,
        SmsMessageAuthorType $authorType = SmsMessageAuthorType::AUTOMATIC,
        bool $isSupervision = false,
    ): SmsBulkSendResultDTO {
        $sent = [];
        $optedOut = [];
        $failed = [];

        foreach (array_values(array_unique($phones)) as $phone) {
            try {
                $this->sendService->send(new SendSmsDTO(
                    organizerId: $organizerId,
                    phone: $phone,
                    content: $content,
                    authorType: $authorType,
                    keepDraft: true,
                    isSupervision: $isSupervision,
                ));
                $sent[] = $phone;
            } catch (SmsRecipientOptedOutException) {
                $optedOut[] = $phone;
            } catch (InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
                $failed[] = $phone;
                $this->logger->warning('Direct SMS not sent', [
                    'organizer_id' => $organizerId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return new SmsBulkSendResultDTO($sent, $optedOut, $failed);
    }
}
