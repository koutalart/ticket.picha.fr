<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PrintJobDomainObjectAbstract;
use HiEvents\Exceptions\InvalidZebraPrinterHostException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\BoxOfficePrinterPreferenceRepositoryInterface;
use HiEvents\Repository\Interfaces\PrintJobRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\PrintBoxOfficeZplDTO;
use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\TicketDataFactory;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;

class PrintBoxOfficeZplHandler
{
    private const PRINTER_PORT = 9100;

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly PrintJobRepositoryInterface $printJobRepository,
        private readonly BoxOfficePrinterPreferenceRepositoryInterface $printerPreferenceRepository,
        private readonly AttendeeTicketZplService $attendeeTicketZplService,
        private readonly TicketDataFactory $ticketDataFactory,
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

        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $dto->attendee_public_id,
            AttendeeDomainObjectAbstract::EVENT_ID => $dto->event_id,
        ]);

        if ($attendee === null) {
            throw new ResourceNotFoundException(__('Attendee not found.'));
        }

        $previousLocale = app()->getLocale();
        app()->setLocale('fr');
        try {
            $zpl = $this->attendeeTicketZplService->generate($this->ticketDataFactory->forAttendee($attendee));
        } finally {
            app()->setLocale($previousLocale);
        }

        $this->zebraPrinterClient->send($dto->printer_host, self::PRINTER_PORT, $zpl);

        $this->printJobRepository->create([
            PrintJobDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
            PrintJobDomainObjectAbstract::AGENT_USER_ID => $dto->agent_user_id,
            PrintJobDomainObjectAbstract::PRINTED_AT => now()->toDateTimeString(),
        ]);

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
