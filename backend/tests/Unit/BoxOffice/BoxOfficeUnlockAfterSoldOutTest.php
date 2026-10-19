<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\ProductNotSoldAtBoxOfficeException;
use HiEvents\Models\Event;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeProductsHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeUnlockAfterSoldOutTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_catalogue_hides_locked_product_while_source_product_has_places(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 20.00);
        [$flash] = $this->createFlashSale($event, sold: 1);
        $product->update(['unlock_after_product_id' => $flash->id]);

        $ids = app(GetBoxOfficeProductsHandler::class)->handle($event->id)
            ->map(fn (ProductDomainObject $p) => $p->getId())->all();

        self::assertContains($flash->id, $ids);
        self::assertNotContains($product->id, $ids);
    }

    public function test_catalogue_shows_product_once_source_product_is_sold_out(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 20.00);
        [$flash] = $this->createFlashSale($event, sold: 2);
        $product->update(['unlock_after_product_id' => $flash->id]);

        $ids = app(GetBoxOfficeProductsHandler::class)->handle($event->id)
            ->map(fn (ProductDomainObject $p) => $p->getId())->all();

        self::assertContains($product->id, $ids);
    }

    public function test_sale_of_locked_product_is_rejected(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 20.00);
        $this->attachCheckInList($event, $product);
        [$flash] = $this->createFlashSale($event, sold: 0);
        $product->update(['unlock_after_product_id' => $flash->id]);

        $this->expectException(ProductNotSoldAtBoxOfficeException::class);

        try {
            app(CreateBoxOfficeSaleHandler::class)->handle(new CreateBoxOfficeSaleDTO(
                event_id: $event->id,
                agent_user_id: $user->id,
                product_id: $product->id,
                product_price_id: $productPrice->id,
                phone: '+33612345678',
                first_name: 'Jane',
                last_name: 'Doe',
                email: null,
                locale: 'fr',
                amount: 20.00,
                payment_method: BoxOfficePaymentMethod::CASH,
                amount_collected: 20.00,
                idempotency_key: Str::uuid()->toString(),
                send_confirmation_email: false,
            ));
        } finally {
            self::assertSame(0, DB::table('box_office_sales')->where('product_price_id', $productPrice->id)->count());
        }
    }

    /**
     * @return array{0: Product, 1: ProductPrice}
     */
    private function createFlashSale(Event $event, int $sold): array
    {
        $flash = Product::create([
            'event_id' => $event->id,
            'title' => 'Vente flash',
            'product_type' => ProductType::TICKET->name,
            'type' => 'PAID',
            'order' => 0,
        ]);

        $price = ProductPrice::create([
            'product_id' => $flash->id,
            'price' => 15.00,
            'initial_quantity_available' => 2,
            'quantity_sold' => $sold,
            'order' => 1,
        ]);

        return [$flash, $price];
    }
}
