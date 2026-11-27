<?php

namespace Tests\Unit\Services\Domain\Loyalty;

use Carbon\Carbon;
use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Services\Domain\Loyalty\LoyaltyStampService;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class LoyaltyStampServiceTest extends TestCase
{
    private LoyaltyCardRepositoryInterface $cardRepository;

    private LoyaltyStampService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cardRepository = m::mock(LoyaltyCardRepositoryInterface::class);
        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->service = new LoyaltyStampService($this->cardRepository, $database);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_next_state_adds_one_stamp(): void
    {
        $this->assertSame(
            ['stamps' => 4, 'rewards_earned' => 1, 'reward_unlocked' => false],
            LoyaltyStampService::nextState(3, 1, 9),
        );
    }

    public function test_ninth_stamp_unlocks_the_reward_and_resets(): void
    {
        $this->assertSame(
            ['stamps' => 0, 'rewards_earned' => 1, 'reward_unlocked' => true],
            LoyaltyStampService::nextState(8, 0, 9),
        );
    }

    public function test_stamp_persists_the_new_state(): void
    {
        Carbon::setTestNow('2026-11-27 12:00:00');
        $card = $this->card(stamps: 8, lastStampedAt: '2026-11-26 12:00:00');

        $this->cardRepository->shouldReceive('lockForUpdateById')->with(5)->andReturn($card);
        $this->cardRepository->shouldReceive('updateFromArray')->once()
            ->with(5, m::on(fn (array $a) => $a['stamps'] === 0 && $a['rewards_earned'] === 3 && $a['total_stamps'] === 27))
            ->andReturn($this->card(stamps: 0));

        $result = $this->service->stamp($card, $this->program());

        $this->assertTrue($result->reward_unlocked);
    }

    public function test_refuses_a_second_stamp_within_a_minute(): void
    {
        Carbon::setTestNow('2026-11-27 12:00:30');
        $card = $this->card(stamps: 2, lastStampedAt: '2026-11-27 12:00:00');

        $this->cardRepository->shouldReceive('lockForUpdateById')->andReturn($card);
        $this->cardRepository->shouldNotReceive('updateFromArray');
        $this->expectException(LoyaltyCardException::class);

        $this->service->stamp($card, $this->program());
    }

    private function card(int $stamps, ?string $lastStampedAt = null): LoyaltyCardDomainObject
    {
        return (new LoyaltyCardDomainObject)
            ->setId(5)
            ->setStamps($stamps)
            ->setRewardsEarned(2)
            ->setTotalStamps(26)
            ->setLastStampedAt($lastStampedAt);
    }

    private function program(): LoyaltyProgramDomainObject
    {
        return (new LoyaltyProgramDomainObject)->setId(1)->setStampsRequired(9);
    }
}
