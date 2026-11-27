<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Loyalty;

use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Loyalty\DTO\LoyaltyStampResultDTO;
use HiEvents\Services\Domain\Loyalty\LoyaltyStampService;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;

class StampLoyaltyCardHandler
{
    public function __construct(
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly LoyaltyCardRepositoryInterface $cardRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly LoyaltyStampService $stampService,
        private readonly LoyaltyWalletPassService $walletPassService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws LoyaltyCardException
     */
    public function handle(int $organizerId, string $scannedCode): LoyaltyStampResultDTO
    {
        $publicId = LoyaltyWalletPassService::publicIdFromBarcode($scannedCode);
        if ($publicId === null) {
            throw new LoyaltyCardException(__('This QR code is not a loyalty card.'));
        }

        $program = $this->programRepository->findFirstWhere(['organizer_id' => $organizerId]);
        $card = $program === null ? null : $this->cardRepository->findFirstWhere([
            'public_id' => $publicId,
            'loyalty_program_id' => $program->getId(),
        ]);

        if ($program === null || $card === null) {
            throw new ResourceNotFoundException(__('This loyalty card does not belong to this organizer.'));
        }

        $result = $this->stampService->stamp($card, $program);

        $notification = $result->reward_unlocked
            ? __('Congratulations :name, you unlocked: :reward', [
                'name' => $result->card->getFirstName(),
                'reward' => $program->getRewardDescription(),
            ], 'fr')
            : __('New stamp: :stamps / :required', [
                'stamps' => $result->card->getStamps(),
                'required' => $program->getStampsRequired(),
            ], 'fr');

        $organizer = $this->organizerRepository->findById($organizerId);
        $this->walletPassService->syncCard($program, $result->card, $organizer->getName(), $notification);

        return $result;
    }
}
