<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Loyalty;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Resources\Loyalty\LoyaltyProgramResourcePublic;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetLoyaltyProgramPublicAction extends BaseAction
{
    public function __construct(
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly LoyaltyWalletPassService $walletPassService,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse|Response
    {
        $program = $this->programRepository->findFirstWhere([
            'organizer_id' => $organizerId,
            'is_active' => true,
        ]);

        if ($program === null) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse(array_merge((new LoyaltyProgramResourcePublic($program))->toArray($request), [
            'apple_enabled' => $this->walletPassService->isAppleEnabled(),
            'google_enabled' => $this->walletPassService->isGoogleEnabled(),
        ]), wrapInData: true);
    }
}
