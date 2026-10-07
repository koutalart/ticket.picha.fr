<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\GetBoxOfficeTicketZplDTO;
use HiEvents\Services\Domain\BoxOffice\BoxOfficeTicketZplService;

/**
 * D19d (PICHA_KIOSK_V2_DECISIONS.md): the iOS Kiosk app prints on the Zebra
 * of its own Wi-Fi, which the production server cannot reach. The server
 * only renders the ZPL; the print job is recorded on generation, like the
 * PDF reprint (D14).
 */
class GetBoxOfficeTicketZplHandler
{
    public function __construct(
        private readonly BoxOfficeTicketZplService $boxOfficeTicketZplService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(GetBoxOfficeTicketZplDTO $dto): string
    {
        $ticket = $this->boxOfficeTicketZplService->build($dto->event_id, $dto->attendee_public_id, $dto->label_format);

        $this->boxOfficeTicketZplService->recordPrintJob($ticket->attendee_id, $dto->agent_user_id);

        return $ticket->zpl;
    }
}
