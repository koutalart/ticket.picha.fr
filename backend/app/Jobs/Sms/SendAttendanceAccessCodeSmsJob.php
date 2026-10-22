<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SendAttendanceAccessCodeSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $organizerId,
        public readonly string $phone,
        public readonly string $content,
    ) {}

    public function handle(SmsSendService $smsSendService, LoggerInterface $logger): void
    {
        try {
            $smsSendService->send(new SendSmsDTO(
                organizerId: $this->organizerId,
                phone: $this->phone,
                content: $this->content,
                authorType: SmsMessageAuthorType::CAMPAIGN,
            ));
        } catch (SmsGatewayNotConfiguredException|SmsRecipientOptedOutException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
            $logger->warning('Attendance access code SMS not sent', [
                'organizer_id' => $this->organizerId,
                'phone' => substr($this->phone, 0, 4).'****'.substr($this->phone, -2),
                'reason' => class_basename($exception),
            ]);
        }
    }
}
