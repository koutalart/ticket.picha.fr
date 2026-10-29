<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\CheckInLists\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListStatsPublicHandler;
use Illuminate\Http\JsonResponse;

class GetCheckInListStatsPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetCheckInListStatsPublicHandler $handler,
    ) {}

    public function __invoke(string $checkInListShortId): JsonResponse
    {
        $stats = $this->handler->handle($checkInListShortId);

        return $this->jsonResponse([
            'checked_in' => $stats->checkedIn,
            'paper_checked_in' => $stats->paperCheckedIn,
            'outside' => $stats->outside,
            'paper_not_scanned' => $stats->paperNotScanned,
            'expected' => $stats->expected(),
        ], wrapInData: true);
    }
}
