<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\OrganizerCalendarCodeUnavailableException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Psr\Log\LoggerInterface;

/**
 * Sends the code right away through the organizer's SMS line (its own or the shared line it belongs to).
 * Like every SMS sent by the platform, it is recorded in the organizer's conversation with that number,
 * as an automatic message that does not mark the conversation unread.
 */
class OrganizerCalendarCodeNotifier
{
    public function __construct(
        private readonly SmsSendService $smsSendService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws OrganizerCalendarCodeUnavailableException
     */
    public function send(OrganizerCalendarDomainObject $calendar, string $code, int $validitySeconds): void
    {
        try {
            $this->smsSendService->send(new SendSmsDTO(
                organizerId: $calendar->getOrganizerId(),
                phone: $calendar->getPhone(),
                content: __(':code is your access code to the calendar ":title". Valid for :minutes minutes. Do not share it.', [
                    'code' => $code,
                    'title' => $calendar->getTitle(),
                    'minutes' => (int) ($validitySeconds / 60),
                ], CustomerLocale::get()),
                authorType: SmsMessageAuthorType::AUTOMATIC,
                keepDraft: true,
            ));
        } catch (SmsGatewayNotConfiguredException|SmsRecipientOptedOutException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
            $this->logger->warning('Organizer calendar access code SMS not sent', [
                'calendar_id' => $calendar->getId(),
                'organizer_id' => $calendar->getOrganizerId(),
                'reason' => class_basename($exception),
            ]);

            throw new OrganizerCalendarCodeUnavailableException(
                __('The code could not be sent. Please try again later.', [], CustomerLocale::get())
            );
        }
    }
}
