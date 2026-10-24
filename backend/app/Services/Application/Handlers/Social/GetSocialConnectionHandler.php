<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Services\Application\Handlers\Social\DTO\SocialConnectionStatusDTO;
use HiEvents\Services\Domain\Social\MetaConnectionService;

class GetSocialConnectionHandler
{
    public function __construct(
        private readonly MetaConnectionService $connectionService,
    ) {}

    public function handle(int $accountId, int $organizerId): SocialConnectionStatusDTO
    {
        return new SocialConnectionStatusDTO(
            available: $this->connectionService->isAvailable(),
            connection: $this->connectionService->findForOrganizer($accountId, $organizerId),
        );
    }
}
