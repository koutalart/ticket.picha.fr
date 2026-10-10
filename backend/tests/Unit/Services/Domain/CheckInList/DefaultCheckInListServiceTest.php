<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\CheckInList\CheckInListProductAssociationService;
use HiEvents\Services\Domain\CheckInList\DefaultCheckInListService;
use Mockery as m;
use Tests\TestCase;

class DefaultCheckInListServiceTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private ProductRepositoryInterface $productRepository;
    private CheckInListProductAssociationService $associationService;
    private DefaultCheckInListService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->productRepository = m::mock(ProductRepositoryInterface::class);
        $this->associationService = m::mock(CheckInListProductAssociationService::class);

        $this->service = new DefaultCheckInListService(
            $this->checkInListRepository,
            $this->productRepository,
            $this->associationService,
        );
    }

    public function test_event_gets_a_main_entrance_list(): void
    {
        $this->checkInListRepository->shouldReceive('create')
            ->once()
            ->with(m::on(fn(array $data) => $data['event_id'] === 7 && $data['name'] !== '' && str_starts_with($data['short_id'], 'cil')))
            ->andReturn((new CheckInListDomainObject())->setId(3));

        self::assertSame(3, $this->service->createForEvent(7)->getId());
    }

    public function test_new_ticket_joins_lists_covering_every_ticket_but_not_restricted_ones(): void
    {
        $full = (new CheckInListDomainObject())->setId(1)->setProducts(collect([$this->ticket(10), $this->ticket(11)]));
        $vipOnly = (new CheckInListDomainObject())->setId(2)->setProducts(collect([$this->ticket(11)]));

        $this->checkInListRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->checkInListRepository->shouldReceive('findWhere')->andReturn(collect([$full, $vipOnly]));
        $this->productRepository->shouldReceive('findWhere')
            ->andReturn(collect([$this->ticket(10), $this->ticket(11), $this->ticket(12)]));

        $this->associationService->shouldReceive('addCheckInListToProducts')->once()->with(1, [10, 11, 12], false);

        $this->service->addTicket($this->ticket(12));
    }

    public function test_ticket_of_an_event_without_list_creates_one(): void
    {
        $this->checkInListRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->checkInListRepository->shouldReceive('findWhere')->andReturn(collect());
        $this->checkInListRepository->shouldReceive('create')->once()->andReturn((new CheckInListDomainObject())->setId(5));

        $this->associationService->shouldReceive('addCheckInListToProducts')->once()->with(5, [12], false);

        $this->service->addTicket($this->ticket(12));
    }

    public function test_non_ticket_products_are_ignored(): void
    {
        $this->checkInListRepository->shouldNotReceive('findWhere');
        $this->associationService->shouldNotReceive('addCheckInListToProducts');

        $this->service->addTicket($this->ticket(12)->setProductType(ProductType::GENERAL->name));
    }

    private function ticket(int $id): ProductDomainObject
    {
        return (new ProductDomainObject())->setId($id)->setEventId(7)->setProductType(ProductType::TICKET->name);
    }
}
