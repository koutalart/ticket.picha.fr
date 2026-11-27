<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Loyalty;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Resources\Loyalty\LoyaltyProgramResource;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetLoyaltyProgramAction extends BaseAction
{
    public function __construct(
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly LoyaltyWalletPassService $walletPassService,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $program = $this->programRepository->findFirstWhere(['organizer_id' => $organizerId]);

        return $this->jsonResponse([
            'program' => $program === null ? null : (new LoyaltyProgramResource($program))->toArray($request),
            'apple_enabled' => $this->walletPassService->isAppleEnabled(),
            'google_enabled' => $this->walletPassService->isGoogleEnabled(),
        ], wrapInData: true);
    }
}
