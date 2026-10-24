<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Exceptions\SocialConnectionNotFoundException;
use HiEvents\Services\Domain\Social\MetaConnectionService;

class DisconnectSocialConnectionHandler
{
    public function __construct(
        private readonly MetaConnectionService $connectionService,
    ) {}

    /**
     * @throws SocialConnectionNotFoundException
     */
    public function handle(int $accountId, int $organizerId): void
    {
        $this->connectionService->disconnect($accountId, $organizerId);
    }
}
