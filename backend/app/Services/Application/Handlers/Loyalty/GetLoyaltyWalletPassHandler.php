<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Loyalty;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\WalletNotConfiguredException;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;
use Illuminate\Contracts\Support\Responsable;

class GetLoyaltyWalletPassHandler
{
    public function __construct(
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly LoyaltyCardRepositoryInterface $cardRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly LoyaltyWalletPassService $walletPassService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws WalletNotConfiguredException
     */
    public function applePass(string $publicId): Responsable
    {
        [$program, $card] = $this->load($publicId);

        if ($card->getAppleMobilePassId() === null) {
            $passId = $this->walletPassService->createApplePass($program, $card, $this->organizerName($program));
            $card = $this->cardRepository->updateFromArray($card->getId(), ['apple_mobile_pass_id' => $passId]);
        }

        return $this->walletPassService->applePass($card)
            ?? throw new ResourceNotFoundException(__('This loyalty card was not found.'));
    }

    /**
     * @throws ResourceNotFoundException
     * @throws WalletNotConfiguredException
     */
    public function googleSaveUrl(string $publicId): string
    {
        [$program, $card] = $this->load($publicId);

        if ($card->getGoogleMobilePassId() === null) {
            $passId = $this->walletPassService->createGooglePass($program, $card, $this->organizerName($program));
            $card = $this->cardRepository->updateFromArray($card->getId(), ['google_mobile_pass_id' => $passId]);
        }

        return $this->walletPassService->googleSaveUrl($card)
            ?? throw new ResourceNotFoundException(__('This loyalty card was not found.'));
    }

    /**
     * @return array{0: LoyaltyProgramDomainObject, 1: LoyaltyCardDomainObject}
     *
     * @throws ResourceNotFoundException
     */
    private function load(string $publicId): array
    {
        $card = $this->cardRepository->findFirstWhere(['public_id' => $publicId]);
        $program = $card === null ? null : $this->programRepository->findFirstWhere(['id' => $card->getLoyaltyProgramId()]);

        if ($card === null || $program === null) {
            throw new ResourceNotFoundException(__('This loyalty card was not found.'));
        }

        return [$program, $card];
    }

    private function organizerName(LoyaltyProgramDomainObject $program): string
    {
        return $this->organizerRepository->findById($program->getOrganizerId())->getName();
    }
}
