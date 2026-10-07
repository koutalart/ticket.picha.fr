<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\GetBoxOfficeTicketZplDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeTicketZplHandler;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class GetBoxOfficeTicketZplHandlerTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_returns_ticket_zpl_without_contacting_any_printer(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->never();
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        $zpl = app(GetBoxOfficeTicketZplHandler::class)->handle(new GetBoxOfficeTicketZplDTO(
            event_id: $event->id,
            attendee_public_id: $attendee->public_id,
            agent_user_id: $user->id,
        ));

        self::assertStringStartsWith('^XA', $zpl);
        self::assertStringContainsString('^FDQA,'.$attendee->public_id.'^FS', $zpl);
        self::assertStringEndsWith('^XZ', $zpl);
    }

    public function test_records_print_job_for_audit(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        app(GetBoxOfficeTicketZplHandler::class)->handle(new GetBoxOfficeTicketZplDTO(
            event_id: $event->id,
            attendee_public_id: $attendee->public_id,
            agent_user_id: $user->id,
        ));

        self::assertSame(1, DB::table('print_jobs')
            ->where('attendee_id', $attendee->id)
            ->where('agent_user_id', $user->id)
            ->count());
    }

    public function test_prints_the_same_8_by_8_ticket_whatever_the_station_label_format(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $zpl = app(GetBoxOfficeTicketZplHandler::class)->handle(new GetBoxOfficeTicketZplDTO(
            event_id: $event->id,
            attendee_public_id: $attendee->public_id,
            agent_user_id: $user->id,
            label_format: new ZplLabelFormatDTO(dpi: 300, width_mm: 80.0, length_mm: 101.0),
        ));

        self::assertStringContainsString("^PW639\n", $zpl);
        self::assertStringContainsString("^LL639\n", $zpl);
    }

    public function test_attendee_of_another_event_is_not_found(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        [$otherEvent] = $this->createEventWithProduct(price: 10.00);

        $this->expectException(ResourceNotFoundException::class);

        app(GetBoxOfficeTicketZplHandler::class)->handle(new GetBoxOfficeTicketZplDTO(
            event_id: $otherEvent->id,
            attendee_public_id: $attendee->public_id,
            agent_user_id: $user->id,
        ));
    }
}
