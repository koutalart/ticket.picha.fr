<?php

namespace HiEvents\Services\Application\Handlers\Booth;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Booth\DTO\BoothTicketVerificationDTO;

class VerifyBoothTicketHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    )
    {
    }

    public function handle(int $eventId, string $attendeePublicId): BoothTicketVerificationDTO
    {
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $attendeePublicId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if (!$attendee) {
            return new BoothTicketVerificationDTO(
                valid: false,
                status: null,
                event_id: $eventId,
                product_id: null,
            );
        }

        return new BoothTicketVerificationDTO(
            valid: $attendee->getStatus() === AttendeeStatus::ACTIVE->name,
            status: $attendee->getStatus(),
            event_id: $eventId,
            product_id: $attendee->getProductId(),
        );
    }
}
