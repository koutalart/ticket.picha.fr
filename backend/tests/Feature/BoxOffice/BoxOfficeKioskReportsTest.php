<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeKioskReportsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    private function salePayload(int $productId, int $productPriceId): array
    {
        return [
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.test',
            'locale' => 'en',
            'payment_method' => 'CASH',
            'amount' => 25.00,
            'amount_collected' => 25.00,
            'send_confirmation_email' => false,
            'idempotency_key' => Str::uuid()->toString(),
        ];
    }

    public function test_operator_lists_only_own_completed_sales(): void
    {
        [$event, $product, $productPrice, $admin] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $this->attachCheckInList($event, $product);

        $operatorA = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $operatorB = $this->makeBoxOfficeOperator($event, self::PASSWORD);

        $tokenA = $this->loginAndGetToken($operatorA, self::PASSWORD);
        $this->postJson("/events/{$event->id}/box-office-sales", $this->salePayload($product->id, $productPrice->id), ['Authorization' => 'Bearer '.$tokenA])
            ->assertCreated();

        $list = $this->getJson("/events/{$event->id}/box-office-sales", ['Authorization' => 'Bearer '.$tokenA]);
        $list->assertOk();
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.agent_user_id', $operatorA->id);
        $list->assertJsonPath('data.0.attendee_name', 'Jane Doe');
        $list->assertJsonPath('data.0.ticket_count', 1);
        $list->assertJsonPath('data.0.checked_in_count', 0);
        $list->assertJsonCount(1, 'data.0.attendee_public_ids');

        $tokenB = $this->loginAndGetToken($operatorB, self::PASSWORD);
        $this->postJson("/events/{$event->id}/box-office-sales", $this->salePayload($product->id, $productPrice->id), ['Authorization' => 'Bearer '.$tokenB])
            ->assertCreated();

        $asB = $this->getJson("/events/{$event->id}/box-office-sales", ['Authorization' => 'Bearer '.$tokenB]);
        $asB->assertOk();
        $asB->assertJsonCount(1, 'data');
        $asB->assertJsonPath('data.0.agent_user_id', $operatorB->id);

        $adminToken = $this->loginAndGetToken($admin, self::PASSWORD);
        $all = $this->getJson("/events/{$event->id}/box-office-sales", ['Authorization' => 'Bearer '.$adminToken]);
        $all->assertOk();
        $all->assertJsonCount(2, 'data');
    }

    public function test_operator_cannot_list_sales_on_unassigned_event(): void
    {
        [$eventA] = $this->createEventWithProduct(price: 25.00);
        [$eventB, $productB] = $this->createEventWithProductOnAccount($eventA->account_id);
        $this->attachCheckInList($eventB, $productB);
        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$eventB->id}/box-office-sales", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
        $this->getJson("/events/{$eventB->id}/box-office-stats", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    public function test_operator_stats_are_scoped_to_own_sales(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $this->attachCheckInList($event, $product);

        $operatorA = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $operatorB = $this->makeBoxOfficeOperator($event, self::PASSWORD);

        $tokenA = $this->loginAndGetToken($operatorA, self::PASSWORD);
        $this->postJson("/events/{$event->id}/box-office-sales", $this->salePayload($product->id, $productPrice->id), ['Authorization' => 'Bearer '.$tokenA])
            ->assertCreated();

        $tokenB = $this->loginAndGetToken($operatorB, self::PASSWORD);
        $this->postJson("/events/{$event->id}/box-office-sales", $this->salePayload($product->id, $productPrice->id), ['Authorization' => 'Bearer '.$tokenB])
            ->assertCreated();

        $tokenA = $this->loginAndGetToken($operatorA, self::PASSWORD);
        $stats = $this->getJson("/events/{$event->id}/box-office-stats", ['Authorization' => 'Bearer '.$tokenA]);
        $stats->assertOk();
        $stats->assertJsonPath('data.sales_count', 1);
        $stats->assertJsonPath('data.ticket_count', 1);
        $stats->assertJsonPath('data.total_collected', 25);
    }

    public function test_operator_can_search_sales_by_attendee_name(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson("/events/{$event->id}/box-office-sales", $this->salePayload($product->id, $productPrice->id), ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        $match = $this->getJson("/events/{$event->id}/box-office-sales?query=Jane%20Doe", ['Authorization' => 'Bearer '.$token]);
        $match->assertOk();
        $match->assertJsonCount(1, 'data');
        $match->assertJsonPath('data.0.attendee_name', 'Jane Doe');

        $miss = $this->getJson("/events/{$event->id}/box-office-sales?query=nobody", ['Authorization' => 'Bearer '.$token]);
        $miss->assertOk();
        $miss->assertJsonCount(0, 'data');
    }

    public function test_list_includes_operator_name_and_scan_status(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $checkInList = $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $operator->forceFill(['first_name' => 'Amina', 'last_name' => 'Said'])->save();
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson("/events/{$event->id}/box-office-sales", $this->salePayload($product->id, $productPrice->id), ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        $list = $this->getJson("/events/{$event->id}/box-office-sales", ['Authorization' => 'Bearer '.$token]);
        $list->assertOk();
        $list->assertJsonPath('data.0.agent_name', 'Amina Said');
        $list->assertJsonPath('data.0.checked_in_count', 0);
        $list->assertJsonCount(1, 'data.0.attendee_public_ids');

        $attendee = Attendee::query()->where('public_id', $list->json('data.0.attendee_public_id'))->firstOrFail();
        DB::table('attendee_check_ins')->insert([
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_PREFIX),
            'check_in_list_id' => $checkInList->id,
            'product_id' => $product->id,
            'attendee_id' => $attendee->id,
            'event_id' => $event->id,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scanned = $this->getJson("/events/{$event->id}/box-office-sales", ['Authorization' => 'Bearer '.$token]);
        $scanned->assertOk();
        $scanned->assertJsonPath('data.0.checked_in_count', 1);
    }
}
