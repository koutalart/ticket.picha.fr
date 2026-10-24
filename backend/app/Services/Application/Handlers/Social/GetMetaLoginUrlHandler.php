<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Exceptions\MetaAppNotConfiguredException;
use HiEvents\Services\Domain\Social\MetaConnectionService;

class GetMetaLoginUrlHandler
{
    public function __construct(
        private readonly MetaConnectionService $connectionService,
    ) {}

    /**
     * @throws MetaAppNotConfiguredException
     */
    public function handle(int $accountId, int $organizerId, int $userId): string
    {
        return $this->connectionService->getLoginUrl($accountId, $organizerId, $userId);
    }
}
