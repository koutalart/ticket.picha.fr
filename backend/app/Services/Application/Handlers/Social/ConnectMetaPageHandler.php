<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Services\Domain\Social\MetaConnectionService;

class ConnectMetaPageHandler
{
    public function __construct(
        private readonly MetaConnectionService $connectionService,
    ) {}

    /**
     * @throws MetaOAuthException
     */
    public function handle(int $accountId, int $organizerId, string $selectionKey, string $pageId): OrganizerSocialConnectionDomainObject
    {
        return $this->connectionService->connect($accountId, $organizerId, $selectionKey, $pageId);
    }
}
