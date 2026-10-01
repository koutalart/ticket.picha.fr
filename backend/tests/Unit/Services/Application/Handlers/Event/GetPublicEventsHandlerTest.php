<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicOrganizerEventsDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventsHandler;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class GetPublicEventsHandlerTest extends TestCase
{
    public function testHiddenCategoriesProductsAndPricesAreRemoved(): void
    {
        $visiblePrice = (new ProductPriceDomainObject())->setId(1)->setIsHidden(false);
        $hiddenPrice = (new ProductPriceDomainObject())->setId(2)->setIsHidden(true);

        $visibleProduct = (new ProductDomainObject())->setId(10)->setIsHidden(false)->setIsHiddenWithoutPromoCode(false);
        $visibleProduct->setProductPrices(collect([$visiblePrice, $hiddenPrice]));
        $hiddenProduct = (new ProductDomainObject())->setId(11)->setIsHidden(true)->setIsHiddenWithoutPromoCode(false);
        $hiddenProduct->setProductPrices(collect());

        $visibleCategory = (new ProductCategoryDomainObject())->setId(100)->setIsHidden(false);
        $visibleCategory->setProducts(collect([$visibleProduct, $hiddenProduct]));
        $hiddenCategory = (new ProductCategoryDomainObject())->setId(101)->setIsHidden(true);
        $hiddenCategory->setProducts(collect());

        $event = (new EventDomainObject())->setId(4);
        $event->setProductCategories(collect([$visibleCategory, $hiddenCategory]));

        $organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $organizerRepository->shouldReceive('findById')->andReturn((new OrganizerDomainObject())->setId(4)->setAccountId(1));

        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $eventRepository->shouldReceive('findEvents')->andReturn(new LengthAwarePaginator([$event], 1, 10));

        $handler = new GetPublicEventsHandler($eventRepository, $organizerRepository);
        $result = $handler->handle(new GetPublicOrganizerEventsDTO(
            organizerId: 4,
            queryParams: QueryParamsDTO::fromArray([]),
            authenticatedAccountId: null,
        ));

        $categories = $result->getCollection()->first()->getProductCategories();
        $this->assertSame([100], $categories->map->getId()->all());
        $products = $categories->first()->getProducts();
        $this->assertSame([10], $products->map->getId()->all());
        $this->assertSame([1], $products->first()->getProductPrices()->map->getId()->all());
    }
}
