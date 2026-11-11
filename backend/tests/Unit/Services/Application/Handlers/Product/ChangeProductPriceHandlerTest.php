<?php

namespace Tests\Unit\Services\Application\Handlers\Product;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Exceptions\ProductNotEditableByAssistantException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\ChangeProductPriceHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\ChangeProductPriceDTO;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ChangeProductPriceHandlerTest extends TestCase
{
    private MockInterface $products;

    private MockInterface $prices;

    private MockInterface $dispatcher;

    private ChangeProductPriceHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = Mockery::mock(ProductRepositoryInterface::class);
        $this->prices = Mockery::mock(ProductPriceRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(DomainEventDispatcherService::class);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->handler = new ChangeProductPriceHandler($this->products, $this->prices, $this->dispatcher, $database);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function product(string $type = 'PAID', bool $refundProtection = false): void
    {
        $this->products->shouldReceive('findFirstWhere')->with(['id' => 12, 'event_id' => 7])->andReturn(
            (new ProductDomainObject)->setId(12)->setType($type)->setIsRefundProtection($refundProtection)
        );
    }

    private function dto(float $price = 20.0, int $priceId = 91): ChangeProductPriceDTO
    {
        return new ChangeProductPriceDTO(eventId: 7, productId: 12, productPriceId: $priceId, price: $price);
    }

    public function test_changes_only_the_price_of_this_product(): void
    {
        $this->product();
        $this->prices->shouldReceive('lockForUpdateById')->with(91)
            ->andReturn((new ProductPriceDomainObject)->setId(91)->setProductId(12)->setPrice(15.0));
        $this->prices->shouldReceive('updateWhere')->once()->with(['price' => 20.0], ['id' => 91, 'product_id' => 12]);
        $this->prices->shouldReceive('findById')->with(91)->andReturn((new ProductPriceDomainObject)->setId(91)->setPrice(20.0));
        $this->dispatcher->shouldReceive('dispatch')->once();

        $this->assertSame(20.0, $this->handler->handle($this->dto())->getPrice());
    }

    public function test_a_price_of_another_product_is_refused(): void
    {
        $this->product();
        $this->prices->shouldReceive('lockForUpdateById')->with(93)
            ->andReturn((new ProductPriceDomainObject)->setId(93)->setProductId(40));
        $this->prices->shouldNotReceive('updateWhere');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle($this->dto(priceId: 93));
    }

    public function test_the_cancellation_protection_is_never_changed(): void
    {
        $this->product(refundProtection: true);
        $this->prices->shouldNotReceive('updateWhere');

        $this->expectException(ProductNotEditableByAssistantException::class);
        $this->handler->handle($this->dto());
    }

    public function test_a_free_ticket_is_not_changed(): void
    {
        $this->product(type: 'FREE');
        $this->prices->shouldNotReceive('updateWhere');

        $this->expectException(ProductNotEditableByAssistantException::class);
        $this->handler->handle($this->dto());
    }

    public function test_a_paid_ticket_cannot_go_to_zero(): void
    {
        $this->product();
        $this->prices->shouldNotReceive('updateWhere');

        $this->expectException(ProductNotEditableByAssistantException::class);
        $this->handler->handle($this->dto(price: 0.0));
    }
}
