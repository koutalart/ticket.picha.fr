<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Jobs\Sms\SendSmsCheckInWelcomeJob;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\CreateAttendeeCheckInPublicDTO;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use HiEvents\Services\Domain\CheckInList\DTO\CreateAttendeeCheckInsResponseDTO;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\CheckinEvent;
use Psr\Log\LoggerInterface;
use Throwable;

class CreateAttendeeCheckInPublicHandler
{
    public function __construct(
        private readonly CreateAttendeeCheckInService $createAttendeeCheckInService,
        private readonly LoggerInterface              $logger,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    )
    {
    }

    /**
     * @throws CannotCheckInException|Throwable
     */
    public function handle(CreateAttendeeCheckInPublicDTO $checkInData): CreateAttendeeCheckInsResponseDTO
    {
        $checkIns = $this->createAttendeeCheckInService->checkInAttendees(
            $checkInData->checkInListUuid,
            $checkInData->checkInUserIpAddress,
            $checkInData->attendeesAndActions,
        );

        $this->logger->info('Attendee check-ins created', [
            'attendee_ids' => $checkIns->attendeeCheckIns
                ->map(fn(AttendeeCheckInDomainObject $checkIn) => $checkIn->getAttendeeId())->toArray(),
            'check_in_list_uuid' => $checkInData->checkInListUuid,
            'ip_address' => $checkInData->checkInUserIpAddress,
        ]);

        /** @var AttendeeCheckInDomainObject $checkIn */
        foreach ($checkIns->attendeeCheckIns as $checkIn) {
            $this->domainEventDispatcherService->dispatch(
                new CheckinEvent(
                    type: DomainEventType::CHECKIN_CREATED,
                    attendeeCheckinId: $checkIn->getId(),
                )
            );
        }

        $this->queueWelcomeSms($checkIns);

        return $checkIns;
    }

    /**
     * The welcome SMS never delays nor fails the scan.
     */
    private function queueWelcomeSms(CreateAttendeeCheckInsResponseDTO $checkIns): void
    {
        /** @var AttendeeCheckInDomainObject $checkIn */
        foreach ($checkIns->createdAttendeeCheckIns ?? [] as $checkIn) {
            try {
                SendSmsCheckInWelcomeJob::dispatch($checkIn->getEventId(), $checkIn->getAttendeeId());
            } catch (Throwable $exception) {
                $this->logger->warning('Check-in welcome SMS could not be queued', [
                    'attendee_id' => $checkIn->getAttendeeId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
