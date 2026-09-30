<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\ProductNotSoldAtBoxOfficeException;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeProductsHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeProductVisibilityTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_new_products_are_visible_at_box_office_by_default(): void
    {
        [, $product] = $this->createEventWithProduct(price: 25.00);

        self::assertTrue((bool) $product->fresh()->is_visible_at_box_office);
    }

    public function test_catalogue_excludes_products_not_visible_at_box_office(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 25.00);
        $product->update(['is_visible_at_box_office' => false]);

        $products = app(GetBoxOfficeProductsHandler::class)->handle($event->id);

        self::assertFalse($products->contains(fn (ProductDomainObject $p) => $p->getId() === $product->id));
    }

    public function test_product_hidden_on_website_is_still_offered_at_box_office(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 25.00);
        $product->update(['is_hidden' => true]);

        $products = app(GetBoxOfficeProductsHandler::class)->handle($event->id);

        self::assertTrue($products->contains(fn (ProductDomainObject $p) => $p->getId() === $product->id));
    }

    public function test_sale_of_product_not_visible_at_box_office_is_rejected(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        $product->update(['is_visible_at_box_office' => false]);

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
                amount: 25.00,
                payment_method: BoxOfficePaymentMethod::CASH,
                amount_collected: 25.00,
                idempotency_key: Str::uuid()->toString(),
                send_confirmation_email: false,
            ));
        } finally {
            self::assertSame(0, DB::table('box_office_sales')->where('product_price_id', $productPrice->id)->count());
        }
    }
}
