<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\RegistrationConfirmation;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsRegistrationConfirmationDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SmsRegistrationConfirmationDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsRegistrationConfirmationStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsRegistrationConfirmationRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsRegistrationConfirmationResultDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderPersonalLinkProviderInterface;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends one confirmation SMS to each active attendee of a completed online order, with their personal attendance link.
 * Each attendee is claimed in sms_registration_confirmations before the call to PICHA SMS, so nobody receives it twice.
 */
class SmsRegistrationConfirmationService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
        private readonly SmsRegistrationConfirmationRepositoryInterface $confirmationRepository,
        private readonly SmsReminderMessageRenderer $renderer,
        private readonly SmsReminderPersonalLinkProviderInterface $linkProvider,
        private readonly SmsSendService $sendService,
        private readonly LoggerInterface $logger,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    /**
     * Box office sales and attendees added by the organizer are marked as manually created: they receive nothing.
     */
    public static function isEligibleOrder(OrderDomainObject $order): bool
    {
        return $order->getStatus() === OrderStatus::COMPLETED->name
            && ! $order->getIsManuallyCreated();
    }

    public static function defaultMessage(CustomerAddressForm $addressForm = CustomerAddressForm::VOUS): string
    {
        return __(
            'Confirmed, your place for {evenement} is booked. See you on {date}, welcome from {heure}. Manage my attendance: {lien}',
            [],
            $addressForm === CustomerAddressForm::TU ? CustomerLocale::FRENCH_TUTOIEMENT : 'fr',
        );
    }

    public function isEnabledForEvent(int $eventId): bool
    {
        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettingsRepository->findFirstWhere([EventSettingDomainObjectAbstract::EVENT_ID => $eventId]);

        return $settings !== null && $settings->getSmsRegistrationConfirmationEnabled();
    }

    public function sendForOrder(int $orderId): SmsRegistrationConfirmationResultDTO
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirst($orderId);
        if ($order === null || ! self::isEligibleOrder($order)) {
            return new SmsRegistrationConfirmationResultDTO;
        }

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettingsRepository->findFirstWhere([EventSettingDomainObjectAbstract::EVENT_ID => $order->getEventId()]);
        if ($settings === null || ! $settings->getSmsRegistrationConfirmationEnabled()) {
            return new SmsRegistrationConfirmationResultDTO;
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $order->getEventId()]);
        if ($event === null || $event->getOrganizerId() === null) {
            return new SmsRegistrationConfirmationResultDTO;
        }

        $template = trim((string) $settings->getSmsRegistrationConfirmationMessage());
        if ($template === '') {
            $template = self::defaultMessage($this->customerLocaleResolver->addressFormForOrganizerId($event->getOrganizerId()));
        }

        $sent = 0;
        $failed = 0;
        $withoutValidPhone = 0;
        $optedOut = 0;
        $alreadySent = 0;

        foreach ($this->directoryRepository->findActiveAttendeesWithPhone($event->getId(), $order->getId()) as $attendee) {
            $phone = $attendee->phone !== null ? SmsPhoneNormalizer::normalize($attendee->phone) : null;
            if ($phone === null) {
                $withoutValidPhone++;

                continue;
            }

            if ($this->isOptedOut($event->getOrganizerId(), $phone)) {
                $optedOut++;

                continue;
            }

            $confirmation = $this->claim($order, $event, $attendee, $phone);
            if ($confirmation === null) {
                $alreadySent++;

                continue;
            }

            try {
                $message = $this->sendService->send(new SendSmsDTO(
                    organizerId: $event->getOrganizerId(),
                    phone: $phone,
                    content: $this->renderer->render(
                        $template,
                        $event,
                        $attendee->firstName,
                        $this->linkProvider->findForAttendee($attendee->attendeeId),
                    ),
                    authorType: SmsMessageAuthorType::AUTOMATIC,
                ));

                $this->confirmationRepository->updateFromArray($confirmation->getId(), [
                    SmsRegistrationConfirmationDomainObjectAbstract::STATUS => SmsRegistrationConfirmationStatus::SENT->name,
                    SmsRegistrationConfirmationDomainObjectAbstract::SMS_MESSAGE_ID => $message->getId(),
                ]);
                $sent++;
            } catch (SmsGatewayNotConfiguredException $exception) {
                $this->fail($confirmation, $attendee, $phone, $exception);
                $failed++;

                break;
            } catch (SmsRecipientOptedOutException|InvalidSmsPhoneNumberException|SmsGatewayException|Throwable $exception) {
                $this->fail($confirmation, $attendee, $phone, $exception);
                $failed++;
            }
        }

        return new SmsRegistrationConfirmationResultDTO(
            sent: $sent,
            failed: $failed,
            withoutValidPhone: $withoutValidPhone,
            optedOut: $optedOut,
            alreadySent: $alreadySent,
        );
    }

    private function isOptedOut(int $organizerId, string $phone): bool
    {
        return $this->optOutRepository->findFirstWhere([
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
        ]) !== null;
    }

    private function claim(OrderDomainObject $order, EventDomainObject $event, SmsReminderAttendeeDTO $attendee, string $phone): ?SmsRegistrationConfirmationDomainObject
    {
        if ($this->confirmationRepository->findFirstWhere([
            SmsRegistrationConfirmationDomainObjectAbstract::ATTENDEE_ID => $attendee->attendeeId,
        ]) !== null) {
            return null;
        }

        try {
            return $this->confirmationRepository->create([
                SmsRegistrationConfirmationDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
                SmsRegistrationConfirmationDomainObjectAbstract::EVENT_ID => $event->getId(),
                SmsRegistrationConfirmationDomainObjectAbstract::ORDER_ID => $order->getId(),
                SmsRegistrationConfirmationDomainObjectAbstract::ATTENDEE_ID => $attendee->attendeeId,
                SmsRegistrationConfirmationDomainObjectAbstract::PHONE => $phone,
                SmsRegistrationConfirmationDomainObjectAbstract::STATUS => SmsRegistrationConfirmationStatus::SENDING->name,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function fail(SmsRegistrationConfirmationDomainObject $confirmation, SmsReminderAttendeeDTO $attendee, string $phone, Throwable $exception): void
    {
        $this->logger->warning('SMS registration confirmation not sent', [
            'sms_registration_confirmation_id' => $confirmation->getId(),
            'attendee_id' => $attendee->attendeeId,
            'phone' => substr($phone, 0, 4).'****'.substr($phone, -2),
            'reason' => class_basename($exception),
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->confirmationRepository->updateFromArray($confirmation->getId(), [
                SmsRegistrationConfirmationDomainObjectAbstract::STATUS => SmsRegistrationConfirmationStatus::FAILED->name,
                SmsRegistrationConfirmationDomainObjectAbstract::FAILURE_REASON => mb_substr($exception->getMessage(), 0, 500),
            ]);
        } catch (Throwable $updateException) {
            $this->logger->error('SMS registration confirmation status could not be saved', [
                'sms_registration_confirmation_id' => $confirmation->getId(),
                'error' => $updateException->getMessage(),
            ]);
        }
    }
}
