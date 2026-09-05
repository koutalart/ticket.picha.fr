<?php

declare(strict_types=1);

namespace Tests\Unit\Mail\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * T14 (PICHA_BASELINE_TODO.md): summary.blade.php crashed with a
 * TypeError when the event had no start_date — DateHelper::convertFromUTC()
 * requires a non-null string. Not end_date as first suspected: there is no
 * getEndDate() call anywhere in this template, only getStartDate(), twice.
 */
class OrderSummaryTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    public function test_renders_without_crashing_when_event_has_no_start_date(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 25.00);
        self::assertNull($event->start_date, 'this test only proves something if start_date is actually null');

        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => 25.00,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        $eventSetting = EventSetting::where('event_id', $event->id)->first();
        $organizer = Organizer::find($event->organizer_id);

        $mail = new OrderSummary(
            order: OrderDomainObject::hydrateFromModel($order),
            event: EventDomainObject::hydrateFromModel($event),
            organizer: OrganizerDomainObject::hydrateFromModel($organizer),
            eventSettings: EventSettingDomainObject::hydrateFromModel($eventSetting),
            invoice: null,
        );

        $html = $mail->render();

        self::assertStringContainsString($event->title, $html);
    }

    public function test_still_shows_date_and_time_when_event_has_a_start_date(): void
    {
        [$event, $product] = $this->createEventWithProduct(price: 25.00);
        $event->update(['start_date' => '2027-01-15 18:00:00', 'timezone' => 'UTC']);

        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => 25.00,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        $eventSetting = EventSetting::where('event_id', $event->id)->first();
        $organizer = Organizer::find($event->organizer_id);

        $mail = new OrderSummary(
            order: OrderDomainObject::hydrateFromModel($order),
            event: EventDomainObject::hydrateFromModel($event->fresh()),
            organizer: OrganizerDomainObject::hydrateFromModel($organizer),
            eventSettings: EventSettingDomainObject::hydrateFromModel($eventSetting),
            invoice: null,
        );

        $html = $mail->render();

        self::assertStringContainsString('January 15, 2027', $html);
    }
}
