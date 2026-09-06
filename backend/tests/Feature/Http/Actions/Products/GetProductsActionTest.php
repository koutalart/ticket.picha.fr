<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Products;

use HiEvents\Helper\IdHelper;
use HiEvents\Http\ResponseCodes;
use HiEvents\Models\Account;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Characterization + regression test for T16.
 *
 * GET /events/{event_id}/products was returning a 500 on every call because
 * GetProductsHandler passed a flat Collection<ProductDomainObject> into
 * ProductFilterService::filter(), whose contract expects a
 * Collection<ProductCategoryDomainObject>.
 */
class GetProductsActionTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    private User $user;
    private string $authToken;
    private Event $event;
    private Product $product;
    private ProductPrice $productPrice;

    protected function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => [
                'percentage' => 1.5,
                'fixed' => 0,
            ],
        ]);

        $this->user = User::factory()->password(self::PASSWORD)->withAccount()->create();

        /** @var Account $account */
        $account = $this->user->accounts()->first();

        Auth::login($this->user);

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Test Organizer',
            'email' => 'organizer@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->event = Event::create([
            'title' => 'Test Event',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'currency' => 'USD',
            'status' => 'LIVE',
            'short_id' => IdHelper::shortId(IdHelper::EVENT_PREFIX),
        ]);

        EventSetting::create([
            'event_id' => $this->event->id,
        ]);

        $category = ProductCategory::create([
            'event_id' => $this->event->id,
            'name' => 'General Admission',
            'is_hidden' => false,
            'order' => 1,
        ]);

        $this->product = Product::create([
            'event_id' => $this->event->id,
            'product_category_id' => $category->id,
            'title' => 'GA Ticket',
            'product_type' => 'TICKET',
            'type' => 'PAID',
            'order' => 1,
        ]);

        $this->productPrice = ProductPrice::create([
            'product_id' => $this->product->id,
            'price' => 25.00,
            'initial_quantity_available' => 100,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        Auth::logout();

        $loginResponse = $this->postJson('/auth/login', [
            'email' => $this->user->email,
            'password' => self::PASSWORD,
        ]);

        $this->authToken = $loginResponse->headers->get('X-Auth-Token');
    }

    public function test_it_returns_the_event_products_as_a_flat_paginated_list(): void
    {
        $response = $this->getJson(
            "/events/{$this->event->id}/products",
            ['Authorization' => 'Bearer ' . $this->authToken],
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'product_type',
                    'event_id',
                    'product_category_id',
                    'prices' => [
                        '*' => ['id', 'price'],
                    ],
                ],
            ],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);

        $data = $response->json('data');
        self::assertCount(1, $data);
        self::assertSame($this->product->id, $data[0]['id']);
        self::assertSame($this->productPrice->id, $data[0]['prices'][0]['id']);
    }

    public function test_it_returns_an_empty_list_when_the_event_has_no_products(): void
    {
        $this->productPrice->delete();
        $this->product->delete();

        $response = $this->getJson(
            "/events/{$this->event->id}/products",
            ['Authorization' => 'Bearer ' . $this->authToken],
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        self::assertSame([], $response->json('data'));
    }
}
