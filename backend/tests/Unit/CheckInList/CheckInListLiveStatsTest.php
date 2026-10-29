<?php

namespace Tests\Unit\CheckInList;

use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\CheckInList;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class CheckInListLiveStatsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private Event $event;

    private Product $product;

    private int $productPriceId;

    private CheckInList $checkInList;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event, $this->product, $productPrice] = $this->createEventWithProduct();
        $this->productPriceId = $productPrice->id;
        EventSetting::query()->where('event_id', $this->event->id)->update([
            'allow_orders_awaiting_offline_payment_to_check_in' => false,
        ]);
        $this->checkInList = $this->attachCheckInList($this->event, $this->product);
    }

    public function test_paper_tickets_are_only_counted_once_scanned(): void
    {
        $paid = $this->attendee('COMPLETED', 'PAYMENT_RECEIVED', 'STRIPE', 'nadia@example.test');
        $paidInside = $this->attendee('COMPLETED', 'PAYMENT_RECEIVED', 'STRIPE', 'ali@example.test');
        $paperInside = $this->attendee('COMPLETED', 'AWAITING_OFFLINE_PAYMENT', 'OFFLINE', 'sur-place-001@no-mail.picha.invalid', 'Sur place');
        $this->attendee('COMPLETED', 'AWAITING_OFFLINE_PAYMENT', 'OFFLINE', 'sur-place-002@no-mail.picha.invalid', 'Sur place');
        $this->attendee('COMPLETED', 'AWAITING_OFFLINE_PAYMENT', 'OFFLINE', 'sur-place-003@no-mail.picha.invalid', 'Sur place');
        $this->attendee('COMPLETED', 'PAYMENT_RECEIVED', 'STRIPE', 'annule@example.test', 'Annule', 'CANCELLED');
        $this->attendee('AWAITING_OFFLINE_PAYMENT', 'AWAITING_OFFLINE_PAYMENT', 'OFFLINE', 'virement@example.test', 'Virement', 'AWAITING_PAYMENT');

        $this->checkIn($paidInside);
        $this->checkIn($paperInside);

        $stats = app(CheckInListRepositoryInterface::class)->getLiveStatsById($this->checkInList->id);

        $this->assertSame(2, $stats->checkedIn);
        $this->assertSame(1, $stats->paperCheckedIn);
        $this->assertSame(1, $stats->outside);
        $this->assertSame(2, $stats->paperNotScanned);
        $this->assertSame(3, $stats->expected());
        $this->assertNotNull($paid);
    }

    public function test_sur_place_ticket_checks_in_when_offline_payments_cannot_check_in(): void
    {
        $paper = $this->attendee('COMPLETED', 'AWAITING_OFFLINE_PAYMENT', 'OFFLINE', 'sur-place-010@no-mail.picha.invalid', 'Sur place');
        $transfer = $this->attendee('AWAITING_OFFLINE_PAYMENT', 'AWAITING_OFFLINE_PAYMENT', 'OFFLINE', 'virement@example.test', 'Virement', 'AWAITING_PAYMENT');

        $result = app(CreateAttendeeCheckInService::class)->checkInAttendees(
            $this->checkInList->short_id,
            '127.0.0.1',
            collect([
                new AttendeeAndActionDTO(public_id: $paper->public_id, action: AttendeeCheckInActionType::CHECK_IN),
                new AttendeeAndActionDTO(public_id: $transfer->public_id, action: AttendeeCheckInActionType::CHECK_IN),
            ]),
        );

        $this->assertCount(1, $result->createdAttendeeCheckIns);
        $this->assertSame($paper->id, $result->createdAttendeeCheckIns->first()->getAttendeeId());
        $this->assertArrayHasKey($transfer->public_id, $result->errors->toArray());
    }

    private function attendee(
        string $orderStatus,
        string $paymentStatus,
        string $provider,
        string $email,
        string $firstName = 'Nadia',
        string $attendeeStatus = 'ACTIVE',
    ): Attendee {
        $order = Order::create([
            'event_id' => $this->event->id,
            'total_gross' => 0,
            'total_refunded' => 0,
            'currency' => 'EUR',
            'status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'payment_provider' => $provider,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => 'Test',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        return Attendee::create([
            'event_id' => $this->event->id,
            'product_id' => $this->product->id,
            'product_price_id' => $this->productPriceId,
            'order_id' => $order->id,
            'status' => $attendeeStatus,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => 'Test',
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);
    }

    private function checkIn(Attendee $attendee): void
    {
        DB::table('attendee_check_ins')->insert([
            'attendee_id' => $attendee->id,
            'order_id' => $attendee->order_id,
            'product_id' => $attendee->product_id,
            'check_in_list_id' => $this->checkInList->id,
            'event_id' => $this->event->id,
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_PREFIX),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
