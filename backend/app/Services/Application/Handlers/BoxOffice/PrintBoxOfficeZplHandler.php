<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\Exceptions\InvalidZebraPrinterHostException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Repository\Interfaces\BoxOfficePrinterPreferenceRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\PrintBoxOfficeZplDTO;
use HiEvents\Services\Domain\BoxOffice\BoxOfficeTicketZplService;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;

class PrintBoxOfficeZplHandler
{
    private const PRINTER_PORT = 9100;

    public function __construct(
        private readonly BoxOfficePrinterPreferenceRepositoryInterface $printerPreferenceRepository,
        private readonly BoxOfficeTicketZplService $boxOfficeTicketZplService,
        private readonly ZebraPrinterClientInterface $zebraPrinterClient,
    ) {}

    /**
     * @throws InvalidZebraPrinterHostException
     * @throws ResourceNotFoundException
     * @throws ZebraPrinterUnreachableException
     */
    public function handle(PrintBoxOfficeZplDTO $dto): void
    {
        $this->assertPrivateLanHost($dto->printer_host);

        $ticket = $this->boxOfficeTicketZplService->build($dto->event_id, $dto->attendee_public_id, $dto->label_format);

        $this->zebraPrinterClient->send($dto->printer_host, self::PRINTER_PORT, $ticket->zpl);

        $this->boxOfficeTicketZplService->recordPrintJob($ticket->attendee_id, $dto->agent_user_id);

        $this->printerPreferenceRepository->rememberHost($dto->agent_user_id, $dto->event_id, $dto->printer_host);
    }

    /**
     * @throws InvalidZebraPrinterHostException
     */
    private function assertPrivateLanHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidZebraPrinterHostException(
                __('The printer address must be a private IPv4 address on the local network.')
            );
        }

        if (! preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host)) {
            throw new InvalidZebraPrinterHostException(
                __('The printer address must be a private IPv4 address on the local network.')
            );
        }
    }
}
