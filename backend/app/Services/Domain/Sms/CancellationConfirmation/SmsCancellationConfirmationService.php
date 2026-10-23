<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\CancellationConfirmation;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\SmsCancellationSource;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCancellationConfirmationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SmsCancellationConfirmationDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsCancellationConfirmationStatus;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCancellationConfirmationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCancellationConfirmationResultDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends one SMS to a cancelled attendee, saying their registration is cancelled and their place released.
 * Only the participant's own cancellation sends it, unless the organizer also enabled it for their own cancellations.
 * Each attendee is claimed in sms_cancellation_confirmations before the call to PICHA SMS, so nobody receives it twice.
 *
 * Attendees sharing a phone number and cancelled together (within CANCELLATION_WAVE_MINUTES of each other) get a
 * single SMS: the others are recorded as SKIPPED. The claim takes a lock on (event, phone) to settle concurrent jobs.
 */
class SmsCancellationConfirmationService
{
    public const CANCELLATION_WAVE_MINUTES = 10;

    public const SKIP_REASON_ALREADY_CONFIRMED_FOR_PHONE = 'Déjà confirmé pour ce numéro';

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
        private readonly SmsCancellationConfirmationRepositoryInterface $confirmationRepository,
        private readonly SmsReminderMessageRenderer $renderer,
        private readonly SmsCancellationLinkProviderInterface $linkProvider,
        private readonly SmsSendService $sendService,
        private readonly LoggerInterface $logger,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    public static function defaultMessage(CustomerAddressForm $addressForm = CustomerAddressForm::VOUS): string
    {
        return __(
            'Noted, your registration for {evenement} is cancelled and your place has been released. We hope to see you again.',
            [],
            $addressForm === CustomerAddressForm::TU ? CustomerLocale::FRENCH_TUTOIEMENT : 'fr',
        );
    }

    public static function isEnabledFor(?EventSettingDomainObject $settings, SmsCancellationSource $source): bool
    {
        if ($settings === null || ! $settings->getSmsCancellationConfirmationEnabled()) {
            return false;
        }

        return $source === SmsCancellationSource::PARTICIPANT
            || $settings->getSmsCancellationConfirmationOnOrganizerCancel();
    }

    /**
     * A single attendee cancelled: by themselves from their attendance page, or by the organizer from the attendee list.
     */
    public function sendForAttendee(int $attendeeId): SmsCancellationConfirmationResultDTO
    {
        /** @var AttendeeDomainObject|null $attendee */
        $attendee = $this->attendeeRepository->findFirst($attendeeId);
        if ($attendee === null || $attendee->getStatus() !== AttendeeStatus::CANCELLED->name) {
            return new SmsCancellationConfirmationResultDTO;
        }

        $source = $attendee->getCancelledByAttendeeAt() !== null
            ? SmsCancellationSource::PARTICIPANT
            : SmsCancellationSource::ORGANIZER;

        return $this->send($attendee->getEventId(), $attendee->getOrderId(), [$attendee->getId()], $source);
    }

    /**
     * The organizer cancelled or refunded the whole order. Attendees who had cancelled by themselves before are left out.
     */
    public function sendForOrder(int $orderId): SmsCancellationConfirmationResultDTO
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirst($orderId);
        if ($order === null || $order->getStatus() !== OrderStatus::CANCELLED->name) {
            return new SmsCancellationConfirmationResultDTO;
        }

        $attendeeIds = $this->attendeeRepository
            ->findWhere([
                AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
                AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::CANCELLED->name,
            ])
            ->filter(fn (AttendeeDomainObject $attendee) => $attendee->getCancelledByAttendeeAt() === null)
            ->map(fn (AttendeeDomainObject $attendee) => $attendee->getId())
            ->values()
            ->all();

        return $this->send($order->getEventId(), $order->getId(), $attendeeIds, SmsCancellationSource::ORGANIZER);
    }

    /**
     * @param  int[]  $attendeeIds
     */
    private function send(int $eventId, int $orderId, array $attendeeIds, SmsCancellationSource $source): SmsCancellationConfirmationResultDTO
    {
        if ($attendeeIds === []) {
            return new SmsCancellationConfirmationResultDTO;
        }

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettingsRepository->findFirstWhere([EventSettingDomainObjectAbstract::EVENT_ID => $eventId]);
        if (! self::isEnabledFor($settings, $source)) {
            return new SmsCancellationConfirmationResultDTO;
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $eventId]);
        if ($event === null || $event->getOrganizerId() === null) {
            return new SmsCancellationConfirmationResultDTO;
        }

        $template = trim((string) $settings->getSmsCancellationConfirmationMessage());
        if ($template === '') {
            $template = self::defaultMessage($this->customerLocaleResolver->addressFormForOrganizerId($event->getOrganizerId()));
        }

        $sent = 0;
        $failed = 0;
        $withoutValidPhone = 0;
        $optedOut = 0;
        $alreadySent = 0;
        $alreadyConfirmedForPhone = 0;

        foreach ($this->directoryRepository->findAttendeesWithPhone($event->getId(), $attendeeIds) as $attendee) {
            $phone = $attendee->phone !== null ? SmsPhoneNormalizer::normalize($attendee->phone) : null;
            if ($phone === null) {
                $withoutValidPhone++;

                continue;
            }

            if ($this->isOptedOut($event->getOrganizerId(), $phone)) {
                $optedOut++;

                continue;
            }

            $confirmation = $this->claim($orderId, $event, $attendee, $phone, $source);
            if ($confirmation === null) {
                $alreadySent++;

                continue;
            }

            if ($confirmation->getStatus() === SmsCancellationConfirmationStatus::SKIPPED->name) {
                $alreadyConfirmedForPhone++;

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
                        $this->renderer->usesPersonalLink($template)
                            ? $this->linkProvider->findForAttendee($attendee->attendeeId, $event)
                            : null,
                    ),
                    authorType: SmsMessageAuthorType::AUTOMATIC,
                ));

                $this->confirmationRepository->updateFromArray($confirmation->getId(), [
                    SmsCancellationConfirmationDomainObjectAbstract::STATUS => SmsCancellationConfirmationStatus::SENT->name,
                    SmsCancellationConfirmationDomainObjectAbstract::SMS_MESSAGE_ID => $message->getId(),
                ]);
                $sent++;
            } catch (SmsGatewayNotConfiguredException $exception) {
                $this->fail($confirmation, $attendee, $phone, $exception);
                $failed++;

                break;
            } catch (Throwable $exception) {
                $this->fail($confirmation, $attendee, $phone, $exception);
                $failed++;
            }
        }

        return new SmsCancellationConfirmationResultDTO(
            sent: $sent,
            failed: $failed,
            withoutValidPhone: $withoutValidPhone,
            optedOut: $optedOut,
            alreadySent: $alreadySent,
            alreadyConfirmedForPhone: $alreadyConfirmedForPhone,
        );
    }

    private function isOptedOut(int $organizerId, string $phone): bool
    {
        return $this->optOutRepository->findFirstWhere([
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
        ]) !== null;
    }

    private function claim(
        int $orderId,
        EventDomainObject $event,
        SmsReminderAttendeeDTO $attendee,
        string $phone,
        SmsCancellationSource $source,
    ): ?SmsCancellationConfirmationDomainObject {
        if ($this->confirmationRepository->findFirstWhere([
            SmsCancellationConfirmationDomainObjectAbstract::ATTENDEE_ID => $attendee->attendeeId,
        ]) !== null) {
            return null;
        }

        try {
            return $this->confirmationRepository->createUnlessPhoneConfirmedSince(
                [
                    SmsCancellationConfirmationDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
                    SmsCancellationConfirmationDomainObjectAbstract::EVENT_ID => $event->getId(),
                    SmsCancellationConfirmationDomainObjectAbstract::ORDER_ID => $orderId,
                    SmsCancellationConfirmationDomainObjectAbstract::ATTENDEE_ID => $attendee->attendeeId,
                    SmsCancellationConfirmationDomainObjectAbstract::PHONE => $phone,
                    SmsCancellationConfirmationDomainObjectAbstract::CANCELLED_BY => $source->name,
                    SmsCancellationConfirmationDomainObjectAbstract::STATUS => SmsCancellationConfirmationStatus::SENDING->name,
                ],
                Carbon::now()->subMinutes(self::CANCELLATION_WAVE_MINUTES)->toDateTimeString(),
                [
                    SmsCancellationConfirmationDomainObjectAbstract::STATUS => SmsCancellationConfirmationStatus::SKIPPED->name,
                    SmsCancellationConfirmationDomainObjectAbstract::FAILURE_REASON => self::SKIP_REASON_ALREADY_CONFIRMED_FOR_PHONE,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function fail(SmsCancellationConfirmationDomainObject $confirmation, SmsReminderAttendeeDTO $attendee, string $phone, Throwable $exception): void
    {
        $this->logger->warning('SMS cancellation confirmation not sent', [
            'sms_cancellation_confirmation_id' => $confirmation->getId(),
            'attendee_id' => $attendee->attendeeId,
            'phone' => substr($phone, 0, 4).'****'.substr($phone, -2),
            'reason' => class_basename($exception),
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->confirmationRepository->updateFromArray($confirmation->getId(), [
                SmsCancellationConfirmationDomainObjectAbstract::STATUS => SmsCancellationConfirmationStatus::FAILED->name,
                SmsCancellationConfirmationDomainObjectAbstract::FAILURE_REASON => mb_substr($exception->getMessage(), 0, 500),
            ]);
        } catch (Throwable $updateException) {
            $this->logger->error('SMS cancellation confirmation status could not be saved', [
                'sms_cancellation_confirmation_id' => $confirmation->getId(),
                'error' => $updateException->getMessage(),
            ]);
        }
    }
}
