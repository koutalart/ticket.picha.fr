<?php

declare(strict_types=1);

namespace Tests\Support;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Account;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\CheckInList;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Shared fixture builder for characterization tests that need a real
 * Account/Organizer/Event/Product/ProductPrice graph in the database.
 *
 * There is no factory for Event/Product/ProductPrice/Organizer/CheckInList in
 * this codebase (only Account/User/AccountVatSetting/Order have factories),
 * so this trait builds the graph directly via Eloquent.
 */
trait BoxOfficeTestFixtures
{
    private function createUserWithAccount(): User
    {
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => [
                'percentage' => 1.5,
                'fixed' => 0,
            ],
        ]);

        return User::factory()->withAccount()->create();
    }

    /**
     * @return array{0: Event, 1: Product, 2: ProductPrice}
     */
    private function createEventWithProduct(
        float $price = 25.00,
        ?int  $initialQuantityAvailable = 100,
        int   $quantitySold = 0,
    ): array
    {
        $user = $this->createUserWithAccount();

        /** @var Account $account */
        $account = $user->accounts()->first();

        // Event::boot() reads auth()->user()->id unconditionally on creating().
        Auth::login($user);

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Test Organizer',
            'email' => 'organizer@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $event = Event::create([
            'title' => 'Test Event',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'currency' => 'USD',
            'status' => 'LIVE',
            'short_id' => IdHelper::shortId(IdHelper::EVENT_PREFIX),
        ]);

        // Not auto-created outside CreateEventService — CreateAttendeeCheckInService
        // expects a row to exist (non-nullable EventSettingDomainObject return type).
        EventSetting::create([
            'event_id' => $event->id,
            'allow_orders_awaiting_offline_payment_to_check_in' => true,
        ]);

        $product = Product::create([
            'event_id' => $event->id,
            'title' => 'GA Ticket',
            'product_type' => ProductType::TICKET->name,
            'type' => 'PAID',
            'order' => 1,
        ]);

        $productPrice = ProductPrice::create([
            'product_id' => $product->id,
            'price' => $price,
            'initial_quantity_available' => $initialQuantityAvailable,
            'quantity_sold' => $quantitySold,
            'order' => 1,
        ]);

        return [$event, $product, $productPrice];
    }

    private function attachCheckInList(Event $event, Product $product): CheckInList
    {
        $checkInList = CheckInList::create([
            'event_id' => $event->id,
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_LIST_PREFIX),
            'name' => 'Main Entrance',
        ]);

        $checkInList->products()->attach($product->id);

        return $checkInList;
    }
}
