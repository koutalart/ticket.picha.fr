<?php

namespace Tests\Unit\Services\Application\Handlers\Loyalty;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Services\Application\Handlers\Loyalty\DTO\EnrollLoyaltyCardDTO;
use HiEvents\Services\Application\Handlers\Loyalty\EnrollLoyaltyCardHandler;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Mockery as m;
use Tests\TestCase;

class EnrollLoyaltyCardHandlerTest extends TestCase
{
    private LoyaltyProgramRepositoryInterface $programRepository;

    private LoyaltyCardRepositoryInterface $cardRepository;

    private EnrollLoyaltyCardHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programRepository = m::mock(LoyaltyProgramRepositoryInterface::class);
        $this->cardRepository = m::mock(LoyaltyCardRepositoryInterface::class);
        $purifier = m::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(fn (?string $value) => $value);

        $this->handler = new EnrollLoyaltyCardHandler($this->programRepository, $this->cardRepository, $purifier);
    }

    public function test_refuses_an_inactive_program(): void
    {
        $this->programRepository->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => 24, 'is_active' => true])->andReturnNull();
        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(new EnrollLoyaltyCardDTO(organizer_id: 24, first_name: 'Awa', email: 'awa@example.com'));
    }

    public function test_requires_an_email_or_a_phone(): void
    {
        $this->programRepository->shouldReceive('findFirstWhere')->andReturn($this->program());
        $this->expectException(LoyaltyCardException::class);

        $this->handler->handle(new EnrollLoyaltyCardDTO(organizer_id: 24, first_name: 'Awa'));
    }

    public function test_creates_a_card_with_a_normalized_phone(): void
    {
        $this->programRepository->shouldReceive('findFirstWhere')->andReturn($this->program());
        $this->cardRepository->shouldReceive('findFirstWhere')
            ->with(['loyalty_program_id' => 3, 'phone' => '+262639780773'])->andReturnNull();
        $this->cardRepository->shouldReceive('create')->once()->with(m::on(fn (array $a) => $a['phone'] === '+262639780773'
            && $a['email'] === null
            && $a['first_name'] === 'Awa'
            && $a['loyalty_program_id'] === 3
            && strlen($a['public_id']) === 32))->andReturn(new LoyaltyCardDomainObject);

        $this->handler->handle(new EnrollLoyaltyCardDTO(organizer_id: 24, first_name: ' Awa ', phone: '06 39 78 07 73'));
    }

    public function test_returns_the_existing_card_for_a_known_email(): void
    {
        $existing = (new LoyaltyCardDomainObject)->setId(9)->setEmail('awa@example.com')->setPhone('+262639780773');
        $this->programRepository->shouldReceive('findFirstWhere')->andReturn($this->program());
        $this->cardRepository->shouldReceive('findFirstWhere')
            ->with(['loyalty_program_id' => 3, 'email' => 'awa@example.com'])->andReturn($existing);
        $this->cardRepository->shouldNotReceive('create');

        $card = $this->handler->handle(new EnrollLoyaltyCardDTO(organizer_id: 24, first_name: 'Awa', email: 'AWA@example.com'));

        $this->assertSame(9, $card->getId());
    }

    private function program(): LoyaltyProgramDomainObject
    {
        return (new LoyaltyProgramDomainObject)->setId(3)->setOrganizerId(24);
    }
}
