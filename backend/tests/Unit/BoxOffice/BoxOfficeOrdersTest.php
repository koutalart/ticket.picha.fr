<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\BoxOffice\BoxOfficeCheckInAttendeeHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrderFilterDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrderListItemDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleItemDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeOrderHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeOrdersHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeOrdersTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    /**
     * @return array{0: Event, 1: Product, 2: ProductPrice, 3: int}
     */
    private function eventFixture(): array
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        return [$event, $product, $productPrice, $user->id];
    }

    private function onlineOrder(Event $event, Product $product, ProductPrice $productPrice, string $firstName = 'Jane', string $email = 'jane@example.test'): Order
    {
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        Order::whereKey($attendee->order_id)->update(['first_name' => $firstName, 'last_name' => 'Online', 'email' => $email, 'is_manually_created' => false]);
        DB::table('attendees')->where('id', $attendee->id)->update(['first_name' => $firstName]);

        return Order::find($attendee->order_id);
    }

    private function boxOfficeOrder(Event $event, Product $product, ProductPrice $productPrice, int $agentId, int $quantity = 1, string $phone = '+262639123456'): Order
    {
        app(CreateBoxOfficeSaleHandler::class)->handle(new CreateBoxOfficeSaleDTO(
            event_id: $event->id,
            agent_user_id: $agentId,
            product_id: $product->id,
            product_price_id: $productPrice->id,
            phone: $phone,
            first_name: 'Fatima',
            last_name: 'Guichet',
            email: '',
            locale: 'fr',
            amount: 25.00 * $quantity,
            payment_method: BoxOfficePaymentMethod::CASH,
            amount_collected: 25.00 * $quantity,
            idempotency_key: Str::uuid()->toString(),
            items: [new CreateBoxOfficeSaleItemDTO(product_id: $product->id, product_price_id: $productPrice->id, quantity: $quantity)],
        ));

        $orderId = DB::table('box_office_sales')->where('event_id', $event->id)->orderByDesc('id')->value('order_id');

        return Order::find($orderId);
    }

    /**
     * @return BoxOfficeOrderListItemDTO[]
     */
    private function list(int $eventId, ?BoxOfficeOrderFilterDTO $filter = null): array
    {
        return app(GetBoxOfficeOrdersHandler::class)->handle($eventId, $filter ?? new BoxOfficeOrderFilterDTO)->items;
    }

    private function publicIds(array $items): array
    {
        return array_map(static fn (BoxOfficeOrderListItemDTO $item) => $item->public_id, $items);
    }

    public function test_lists_online_and_box_office_orders_newest_first(): void
    {
        [$event, $product, $productPrice, $agentId] = $this->eventFixture();
        $online = $this->onlineOrder($event, $product, $productPrice);
        $boxOffice = $this->boxOfficeOrder($event, $product, $productPrice, $agentId, quantity: 2);

        $items = $this->list($event->id);

        self::assertSame([$boxOffice->public_id, $online->public_id], $this->publicIds($items));
        self::assertSame('BOX_OFFICE', $items[0]->channel);
        self::assertSame(2, $items[0]->ticket_count);
        self::assertSame('+262639123456', $items[0]->phone);
        self::assertSame('ONLINE', $items[1]->channel);
        self::assertSame('jane@example.test', $items[1]->email);
    }

    public function test_excludes_unfinished_orders_and_other_events(): void
    {
        [$event, $product, $productPrice] = $this->eventFixture();
        $kept = $this->onlineOrder($event, $product, $productPrice);
        $reserved = $this->onlineOrder($event, $product, $productPrice);
        $abandoned = $this->onlineOrder($event, $product, $productPrice);
        Order::whereKey($reserved->id)->update(['status' => 'RESERVED']);
        Order::whereKey($abandoned->id)->update(['status' => 'ABANDONED']);
        [$otherEvent, $otherProduct, $otherPrice] = $this->eventFixture();
        $this->onlineOrder($otherEvent, $otherProduct, $otherPrice);

        self::assertSame([$kept->public_id], $this->publicIds($this->list($event->id)));
    }

    public function test_filters_by_channel_and_by_agent(): void
    {
        [$event, $product, $productPrice, $agentId] = $this->eventFixture();
        $online = $this->onlineOrder($event, $product, $productPrice);
        $mine = $this->boxOfficeOrder($event, $product, $productPrice, $agentId);
        $colleague = $this->makeBoxOfficeOperator($event, 'password123');
        $theirs = $this->boxOfficeOrder($event, $product, $productPrice, $colleague->id);

        self::assertSame([$online->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(channel: 'ONLINE'))));
        self::assertSame([$theirs->public_id, $mine->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(channel: 'BOX_OFFICE'))));
        self::assertSame([$mine->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(agent_user_id: $agentId))));
    }

    public function test_orders_added_by_hand_in_the_admin_are_neither_online_nor_box_office(): void
    {
        [$event, $product, $productPrice] = $this->eventFixture();
        $online = $this->onlineOrder($event, $product, $productPrice);
        $manual = $this->onlineOrder($event, $product, $productPrice);
        Order::whereKey($manual->id)->update(['is_manually_created' => true]);

        self::assertSame('MANUAL', $this->list($event->id)[0]->channel);
        self::assertSame([$online->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(channel: 'ONLINE'))));
        self::assertSame([$manual->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(channel: 'MANUAL'))));
    }

    public function test_search_by_buyer_order_number_ticket_number_and_phone(): void
    {
        [$event, $product, $productPrice, $agentId] = $this->eventFixture();
        $online = $this->onlineOrder($event, $product, $productPrice, 'Amina', 'amina@example.test');
        $boxOffice = $this->boxOfficeOrder($event, $product, $productPrice, $agentId, phone: '+262639777888');
        $ticket = DB::table('attendees')->where('order_id', $online->id)->value('public_id');

        foreach (['amina', 'Amina Online', 'amina@example', $online->public_id, $ticket] as $query) {
            self::assertSame([$online->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(query: $query))), "query: $query");
        }
        self::assertSame([$boxOffice->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(query: '777888'))));
    }

    public function test_box_office_placeholder_email_is_hidden(): void
    {
        [$event, $product, $productPrice, $agentId] = $this->eventFixture();
        $this->boxOfficeOrder($event, $product, $productPrice, $agentId);

        self::assertNull($this->list($event->id)[0]->email);
    }

    public function test_counts_entries_and_filters_orders_not_fully_checked_in(): void
    {
        [$event, $product, $productPrice, $agentId] = $this->eventFixture();
        $partial = $this->boxOfficeOrder($event, $product, $productPrice, $agentId, quantity: 2);
        $done = $this->boxOfficeOrder($event, $product, $productPrice, $agentId);
        $checkIn = app(BoxOfficeCheckInAttendeeHandler::class);
        $checkIn->handle($event->id, DB::table('attendees')->where('order_id', $partial->id)->orderBy('id')->value('public_id'), '127.0.0.1');
        $checkIn->handle($event->id, DB::table('attendees')->where('order_id', $done->id)->value('public_id'), '127.0.0.1');

        $items = $this->list($event->id);
        self::assertSame([1, 1], array_map(static fn ($item) => $item->checked_in_count, $items));
        self::assertSame([1, 2], array_map(static fn ($item) => $item->ticket_count, $items));

        self::assertSame([$partial->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(not_checked_in: true))));
    }

    public function test_cancelled_filter(): void
    {
        [$event, $product, $productPrice] = $this->eventFixture();
        $this->onlineOrder($event, $product, $productPrice);
        $cancelled = $this->onlineOrder($event, $product, $productPrice);
        Order::whereKey($cancelled->id)->update(['status' => 'CANCELLED']);

        self::assertSame([$cancelled->public_id], $this->publicIds($this->list($event->id, new BoxOfficeOrderFilterDTO(cancelled: true))));
    }

    public function test_detail_lists_every_ticket_with_its_entry_status(): void
    {
        [$event, $product, $productPrice, $agentId] = $this->eventFixture();
        $order = $this->boxOfficeOrder($event, $product, $productPrice, $agentId, quantity: 2);
        $first = DB::table('attendees')->where('order_id', $order->id)->orderBy('id')->value('public_id');
        app(BoxOfficeCheckInAttendeeHandler::class)->handle($event->id, $first, '127.0.0.1');

        $detail = app(GetBoxOfficeOrderHandler::class)->handle($event->id, $order->public_id);

        self::assertSame($order->public_id, $detail->order->public_id);
        self::assertSame('BOX_OFFICE', $detail->order->channel);
        self::assertCount(2, $detail->tickets);
        self::assertSame($first, $detail->tickets[0]->public_id);
        self::assertNotNull($detail->tickets[0]->checked_in_at);
        self::assertNull($detail->tickets[1]->checked_in_at);
        self::assertSame($product->title, $detail->tickets[1]->product_title);
        self::assertNull($detail->tickets[0]->email);
    }

    public function test_detail_of_another_events_order_is_not_found(): void
    {
        [$event] = $this->eventFixture();
        [$otherEvent, $otherProduct, $otherPrice] = $this->eventFixture();
        $foreign = $this->onlineOrder($otherEvent, $otherProduct, $otherPrice);

        $this->expectException(ResourceNotFoundException::class);

        app(GetBoxOfficeOrderHandler::class)->handle($event->id, $foreign->public_id);
    }
}
