<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Loyalty;

use Carbon\Carbon;
use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Services\Domain\Loyalty\DTO\LoyaltyStampResultDTO;
use Illuminate\Database\DatabaseManager;

class LoyaltyStampService
{
    public const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly LoyaltyCardRepositoryInterface $cardRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws LoyaltyCardException
     */
    public function stamp(LoyaltyCardDomainObject $card, LoyaltyProgramDomainObject $program): LoyaltyStampResultDTO
    {
        return $this->databaseManager->transaction(function () use ($card, $program) {
            $locked = $this->cardRepository->lockForUpdateById($card->getId()) ?? $card;

            if ($this->isTooSoon($locked)) {
                throw new LoyaltyCardException(__('This card was stamped less than a minute ago.'));
            }

            $next = self::nextState($locked->getStamps(), $locked->getRewardsEarned(), $program->getStampsRequired());

            $updated = $this->cardRepository->updateFromArray($locked->getId(), [
                'stamps' => $next['stamps'],
                'rewards_earned' => $next['rewards_earned'],
                'total_stamps' => $locked->getTotalStamps() + 1,
                'last_stamped_at' => Carbon::now(),
            ]);

            return new LoyaltyStampResultDTO(card: $updated, reward_unlocked: $next['reward_unlocked']);
        });
    }

    /**
     * @return array{stamps: int, rewards_earned: int, reward_unlocked: bool}
     */
    public static function nextState(int $stamps, int $rewardsEarned, int $stampsRequired): array
    {
        $stampsRequired = max(1, $stampsRequired);
        $stamps++;

        if ($stamps >= $stampsRequired) {
            return ['stamps' => 0, 'rewards_earned' => $rewardsEarned + 1, 'reward_unlocked' => true];
        }

        return ['stamps' => $stamps, 'rewards_earned' => $rewardsEarned, 'reward_unlocked' => false];
    }

    private function isTooSoon(LoyaltyCardDomainObject $card): bool
    {
        if ($card->getLastStampedAt() === null) {
            return false;
        }

        return Carbon::parse($card->getLastStampedAt())->diffInSeconds(Carbon::now(), true) < self::COOLDOWN_SECONDS;
    }
}
