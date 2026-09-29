<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) — Kiosk v2, D23 (PICHA_KIOSK_V2_DECISIONS.md parcours v2.1).
 *
 * GET /events/{event_id}/box-office/products — the sellable catalogue for the
 * Kiosk "Vente" screen, scoped to a BOX_OFFICE_OPERATOR's event (the native
 * GET /events/{id}/products is ORGANIZER-gated and 403s for an operator).
 */
class BoxOfficeProductsCatalogTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    /** An assigned operator gets the catalogue with server-computed flags. */
    public function test_operator_gets_the_catalogue_for_an_assigned_event(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00, initialQuantityAvailable: 100, quantitySold: 1);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $response = $this->getJson(
            "/events/{$event->id}/box-office/products",
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $product->id);
        $response->assertJsonPath('data.0.product_type', 'TICKET');
        $response->assertJsonPath('data.0.is_scannable', true);
        $response->assertJsonPath('data.0.prices.0.id', $productPrice->id);
        $response->assertJsonPath('data.0.prices.0.price', 25);
        // 100 initial - 1 sold = 99
        $response->assertJsonPath('data.0.prices.0.quantity_remaining', 99);
    }

    /** A product with no active check-in list is flagged not scannable. */
    public function test_product_without_active_check_in_list_is_flagged_not_scannable(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 25.00);
        // no attachCheckInList()
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$event->id}/box-office/products", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.is_scannable', false);
    }

    /** An unlimited price reports quantity_remaining as null. */
    public function test_unlimited_price_reports_null_quantity_remaining(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00, initialQuantityAvailable: null);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$event->id}/box-office/products", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonPath('data.0.prices.0.quantity_remaining', null);
    }

    /** GENERAL (non-ticket) products are not returned. */
    public function test_general_products_are_excluded(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $general = Product::create([
            'event_id' => $event->id,
            'title' => 'T-shirt',
            'product_type' => 'GENERAL',
            'type' => 'PAID',
            'order' => 2,
        ]);
        ProductPrice::create([
            'product_id' => $general->id,
            'price' => 15.00,
            'initial_quantity_available' => 50,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $response = $this->getJson("/events/{$event->id}/box-office/products", ['Authorization' => 'Bearer '.$token]);
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($product->id, $ids);
        $this->assertNotContains($general->id, $ids);
    }

    /** AC-v2-2 style: operator not assigned to this event -> 403. */
    public function test_operator_not_assigned_gets_403(): void
    {
        [$eventA] = $this->createEventWithProduct(price: 25.00);
        [$eventB, $productB] = $this->createEventWithProductOnAccount($eventA->account_id);
        $this->attachCheckInList($eventB, $productB);

        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$eventB->id}/box-office/products", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    /** An ORGANIZER of the account can also read the catalogue. */
    public function test_organizer_can_read_the_catalogue(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        $organizer = $this->makeOrganizerOnEvent($event, self::PASSWORD);
        $token = $this->loginAndGetToken($organizer, self::PASSWORD);

        $this->getJson("/events/{$event->id}/box-office/products", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $product->id);
    }
}
