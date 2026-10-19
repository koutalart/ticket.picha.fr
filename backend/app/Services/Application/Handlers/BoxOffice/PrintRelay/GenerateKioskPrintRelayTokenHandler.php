<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay;

use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\KioskPrintRelayTokenDTO;
use HiEvents\Services\Domain\BoxOffice\KioskPrintRelayService;

class GenerateKioskPrintRelayTokenHandler
{
    public function __construct(
        private readonly KioskPrintRelayService $kioskPrintRelayService,
    ) {}

    public function handle(int $eventId): KioskPrintRelayTokenDTO
    {
        return new KioskPrintRelayTokenDTO(
            relay_token: $this->kioskPrintRelayService->generateToken($eventId),
            last_seen_at: null,
        );
    }
}
