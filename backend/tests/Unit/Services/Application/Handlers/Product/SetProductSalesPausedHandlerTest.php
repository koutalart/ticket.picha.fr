<?php

namespace Tests\Unit\Services\Application\Handlers\Product;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\ProductNotEditableByAssistantException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\DTO\SetProductSalesPausedDTO;
use HiEvents\Services\Application\Handlers\Product\SetProductSalesPausedHandler;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SetProductSalesPausedHandlerTest extends TestCase
{
    private MockInterface $products;

    private MockInterface $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = Mockery::mock(ProductRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(DomainEventDispatcherService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function handle(bool $paused = true): ProductDomainObject
    {
        return (new SetProductSalesPausedHandler($this->products, $this->dispatcher))
            ->handle(new SetProductSalesPausedDTO(eventId: 7, productId: 12, salesPaused: $paused));
    }

    public function test_pauses_the_sale_of_the_product_of_this_event(): void
    {
        $this->products->shouldReceive('findFirstWhere')->with(['id' => 12, 'event_id' => 7])
            ->andReturn((new ProductDomainObject)->setId(12));
        $this->products->shouldReceive('updateWhere')->once()->with(['is_sales_paused' => true], ['id' => 12, 'event_id' => 7]);
        $this->products->shouldReceive('findById')->with(12)->andReturn((new ProductDomainObject)->setId(12)->setIsSalesPaused(true));
        $this->dispatcher->shouldReceive('dispatch')->once();

        $this->assertTrue($this->handle()->getIsSalesPaused());
    }

    public function test_a_product_of_another_event_is_refused(): void
    {
        $this->products->shouldReceive('findFirstWhere')->andReturnNull();
        $this->products->shouldNotReceive('updateWhere');

        $this->expectException(ResourceNotFoundException::class);
        $this->handle();
    }

    public function test_the_cancellation_protection_is_never_changed(): void
    {
        $this->products->shouldReceive('findFirstWhere')
            ->andReturn((new ProductDomainObject)->setId(12)->setIsRefundProtection(true));
        $this->products->shouldNotReceive('updateWhere');

        $this->expectException(ProductNotEditableByAssistantException::class);
        $this->handle(paused: false);
    }
}
