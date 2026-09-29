<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeCheckInResultDTO;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;

/**
 * Welcome desk entry: checks an attendee in through the native check-in
 * service (same rules as the door scanner) on the first active check-in list
 * of the attendee's product.
 */
class BoxOfficeCheckInAttendeeHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CreateAttendeeCheckInService $createAttendeeCheckInService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $attendeePublicId, string $ipAddress): BoxOfficeCheckInResultDTO
    {
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $attendeePublicId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($attendee === null) {
            throw new ResourceNotFoundException(__('Attendee not found.'));
        }

        $checkInListShortId = $this->productRepository->findActiveCheckInListShortId($attendee->getProductId());
        if ($checkInListShortId === null) {
            return new BoxOfficeCheckInResultDTO(
                attendee_public_id: $attendeePublicId,
                status: BoxOfficeCheckInResultDTO::REFUSED,
                message: __('This ticket is not attached to an active check-in list.'),
            );
        }

        try {
            $response = $this->createAttendeeCheckInService->checkInAttendees(
                $checkInListShortId,
                $ipAddress,
                collect([new AttendeeAndActionDTO($attendeePublicId, AttendeeCheckInActionType::CHECK_IN)]),
            );
        } catch (CannotCheckInException $exception) {
            return new BoxOfficeCheckInResultDTO(
                attendee_public_id: $attendeePublicId,
                status: BoxOfficeCheckInResultDTO::REFUSED,
                message: $exception->getMessage(),
            );
        }

        $checkIn = $response->attendeeCheckIns->first();
        $error = $response->errors->toArray()[$attendeePublicId] ?? null;

        if ($checkIn !== null && $error === null) {
            return new BoxOfficeCheckInResultDTO(
                attendee_public_id: $attendeePublicId,
                status: BoxOfficeCheckInResultDTO::CHECKED_IN,
                checked_in_at: $checkIn->getCreatedAt(),
            );
        }

        if ($checkIn !== null) {
            return new BoxOfficeCheckInResultDTO(
                attendee_public_id: $attendeePublicId,
                status: BoxOfficeCheckInResultDTO::ALREADY_CHECKED_IN,
                checked_in_at: $checkIn->getCreatedAt(),
                message: $error,
            );
        }

        return new BoxOfficeCheckInResultDTO(
            attendee_public_id: $attendeePublicId,
            status: BoxOfficeCheckInResultDTO::REFUSED,
            message: $error,
        );
    }
}
