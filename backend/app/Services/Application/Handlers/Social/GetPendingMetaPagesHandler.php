<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Infrastructure\Meta\DTO\MetaPageDTO;
use Illuminate\Support\Collection;

class GetPendingMetaPagesHandler
{
    public function __construct(
        private readonly MetaConnectionService $connectionService,
    ) {}

    /**
     * @return Collection<int, MetaPageDTO>
     *
     * @throws MetaOAuthException
     */
    public function handle(int $accountId, int $organizerId, string $selectionKey): Collection
    {
        return collect($this->connectionService->getPendingPages($accountId, $organizerId, $selectionKey));
    }
}
