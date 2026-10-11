<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Helper\IdHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class AwaitingPaymentStatsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    public function test_orders_awaiting_payment_are_reported_apart_from_gross_sales(): void
    {
        [$event, , , $user] = $this->createEventWithProduct(price: 50.00, userPassword: self::PASSWORD);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        foreach ([['COMPLETED', 'AWAITING_OFFLINE_PAYMENT', 100], ['AWAITING_OFFLINE_PAYMENT', 'AWAITING_OFFLINE_PAYMENT', 25.5], ['CANCELLED', 'AWAITING_OFFLINE_PAYMENT', 999], ['COMPLETED', 'PAYMENT_RECEIVED', 40]] as [$status, $paymentStatus, $total]) {
            DB::table('orders')->insert([
                'event_id' => $event->id,
                'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
                'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
                'status' => $status,
                'payment_status' => $paymentStatus,
                'currency' => 'EUR',
                'total_gross' => $total,
                'total_before_additions' => $total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson("/events/{$event->id}/stats", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.total_awaiting_payment', 125.5)
            ->assertJsonPath('data.orders_awaiting_payment', 2);

        $this->getJson("/organizers/{$event->organizer_id}/stats?currency_code=".$event->currency, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.total_awaiting_payment', 125.5)
            ->assertJsonPath('data.orders_awaiting_payment', 2);
    }
}
