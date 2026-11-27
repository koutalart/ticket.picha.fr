<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Loyalty;

use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncLoyaltyProgramPassesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $loyaltyProgramId) {}

    public function handle(
        LoyaltyProgramRepositoryInterface $programRepository,
        LoyaltyCardRepositoryInterface $cardRepository,
        OrganizerRepositoryInterface $organizerRepository,
        LoyaltyWalletPassService $walletPassService,
    ): void {
        $program = $programRepository->findFirstWhere(['id' => $this->loyaltyProgramId]);
        if ($program === null) {
            return;
        }

        $organizerName = $organizerRepository->findById($program->getOrganizerId())->getName();

        foreach ($cardRepository->findWhere(['loyalty_program_id' => $program->getId()]) as $card) {
            if ($card->getAppleMobilePassId() === null && $card->getGoogleMobilePassId() === null) {
                continue;
            }

            $walletPassService->syncCard($program, $card, $organizerName);
        }
    }
}
