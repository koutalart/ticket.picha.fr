<?php

namespace Tests\Unit\Services\Application\Handlers\Loyalty;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Loyalty\StampLoyaltyCardHandler;
use HiEvents\Services\Domain\Loyalty\DTO\LoyaltyStampResultDTO;
use HiEvents\Services\Domain\Loyalty\LoyaltyStampService;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;
use Mockery as m;
use Tests\TestCase;

class StampLoyaltyCardHandlerTest extends TestCase
{
    private const PUBLIC_ID = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';

    private LoyaltyProgramRepositoryInterface $programRepository;

    private LoyaltyCardRepositoryInterface $cardRepository;

    private LoyaltyStampService $stampService;

    private LoyaltyWalletPassService $walletPassService;

    private StampLoyaltyCardHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programRepository = m::mock(LoyaltyProgramRepositoryInterface::class);
        $this->cardRepository = m::mock(LoyaltyCardRepositoryInterface::class);
        $organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $organizerRepository->shouldReceive('findById')->andReturn((new OrganizerDomainObject)->setName('La Terrasse de la Barge'));
        $this->stampService = m::mock(LoyaltyStampService::class);
        $this->walletPassService = m::mock(LoyaltyWalletPassService::class);

        $this->handler = new StampLoyaltyCardHandler(
            $this->programRepository,
            $this->cardRepository,
            $organizerRepository,
            $this->stampService,
            $this->walletPassService,
        );
    }

    public function test_refuses_a_qr_code_that_is_not_a_loyalty_card(): void
    {
        $this->expectException(LoyaltyCardException::class);

        $this->handler->handle(24, 'https://example.com/?x=1');
    }

    public function test_refuses_a_card_from_another_organizer(): void
    {
        $this->programRepository->shouldReceive('findFirstWhere')->with(['organizer_id' => 24])->andReturn($this->program());
        $this->cardRepository->shouldReceive('findFirstWhere')
            ->with(['public_id' => self::PUBLIC_ID, 'loyalty_program_id' => 3])->andReturnNull();
        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(24, 'PICHAFID:'.self::PUBLIC_ID);
    }

    public function test_stamps_and_pushes_the_reward_to_the_phone(): void
    {
        $card = (new LoyaltyCardDomainObject)->setId(5)->setFirstName('Awa')->setPublicId(self::PUBLIC_ID);
        $program = $this->program();

        $this->programRepository->shouldReceive('findFirstWhere')->andReturn($program);
        $this->cardRepository->shouldReceive('findFirstWhere')->andReturn($card);
        $this->stampService->shouldReceive('stamp')->once()->with($card, $program)
            ->andReturn(new LoyaltyStampResultDTO(card: $card->setStamps(0), reward_unlocked: true));
        $this->walletPassService->shouldReceive('syncCard')->once()
            ->with($program, $card, 'La Terrasse de la Barge', m::on(fn (string $message) => str_contains($message, '1 menu offert')));

        $result = $this->handler->handle(24, 'PICHAFID:'.self::PUBLIC_ID);

        $this->assertTrue($result->reward_unlocked);
    }

    private function program(): LoyaltyProgramDomainObject
    {
        return (new LoyaltyProgramDomainObject)->setId(3)->setOrganizerId(24)->setStampsRequired(9)->setRewardDescription('1 menu offert');
    }
}
