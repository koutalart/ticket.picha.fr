<?php

namespace Tests\Unit\Repository;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Repository\Eloquent\SmsConversationRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SmsConversationRepositoryTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PHONE = '+262639091486';

    public function test_orders_without_phone_are_found_through_the_email_of_a_phone_matched_order(): void
    {
        [$event] = $this->createEventWithProduct();

        $paid = $this->order($event, 'Oyhana@Example.test', 25, '2026-10-10 10:00:00');
        $this->phoneAnswer($event, $paid, '06 39 09 14 86');
        $invitation = $this->order($event, 'oyhana@example.test', 0, '2026-10-20 10:00:00');
        $stranger = $this->order($event, 'someone-else@example.test', 0, '2026-10-21 10:00:00');

        $orders = $this->repository()->findOrdersByPhone($event->organizer_id, self::PHONE);

        $this->assertSame([$invitation->id, $paid->id], $orders->pluck('orderId')->all());
        $this->assertNotContains($stranger->id, $orders->pluck('orderId')->all());
    }

    public function test_orders_of_another_organizer_with_the_same_email_are_ignored(): void
    {
        [$event] = $this->createEventWithProduct();
        [$otherEvent] = $this->createEventWithProduct();

        $paid = $this->order($event, 'oyhana@example.test', 25, '2026-10-10 10:00:00');
        $this->phoneAnswer($event, $paid, '+262639091486');
        $elsewhere = $this->order($otherEvent, 'oyhana@example.test', 0, '2026-10-20 10:00:00');

        $ids = $this->repository()->findOrdersByPhone($event->organizer_id, self::PHONE)->pluck('orderId')->all();

        $this->assertSame([$paid->id], $ids);
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_no_phone_match_returns_nothing_even_with_orders_sharing_an_email(): void
    {
        [$event] = $this->createEventWithProduct();
        $this->order($event, 'oyhana@example.test', 0, '2026-10-20 10:00:00');

        $this->assertCount(0, $this->repository()->findOrdersByPhone($event->organizer_id, self::PHONE));
    }

    private function repository(): SmsConversationRepository
    {
        return $this->app->make(SmsConversationRepository::class);
    }

    private function order(Event $event, string $email, float $total, string $createdAt): Order
    {
        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => $total,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'payment_status' => $total > 0 ? 'PAYMENT_RECEIVED' : 'NO_PAYMENT_REQUIRED',
            'first_name' => 'Oyhana',
            'last_name' => 'Inoussa',
            'email' => $email,
            'locale' => 'fr',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
        Order::whereKey($order->id)->update(['created_at' => $createdAt]);

        return $order;
    }

    private function phoneAnswer(Event $event, Order $order, string $phone): void
    {
        $questionId = DB::table('questions')->insertGetId([
            'event_id' => $event->id,
            'title' => 'Téléphone',
            'type' => 'PHONE',
            'belongs_to' => 'ORDER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('question_answers')->insert([
            'question_id' => $questionId,
            'order_id' => $order->id,
            'answer' => json_encode($phone),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
