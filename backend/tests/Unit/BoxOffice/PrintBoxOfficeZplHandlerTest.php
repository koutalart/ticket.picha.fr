<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Exceptions\InvalidZebraPrinterHostException;
use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\PrintBoxOfficeZplDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintBoxOfficeZplHandler;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class PrintBoxOfficeZplHandlerTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    public function test_sends_zpl_to_private_host_and_records_print_job(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')
            ->once()
            ->with('192.168.1.50', 9100, Mockery::on(fn (string $zpl) => str_contains($zpl, $attendee->public_id)));

        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        app(PrintBoxOfficeZplHandler::class)->handle(new PrintBoxOfficeZplDTO(
            event_id: $event->id,
            attendee_public_id: $attendee->public_id,
            agent_user_id: $user->id,
            printer_host: '192.168.1.50',
        ));

        self::assertSame(1, DB::table('print_jobs')->where('attendee_id', $attendee->id)->count());
    }

    public function test_rejects_public_ip_without_contacting_printer(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->never();
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        $this->expectException(InvalidZebraPrinterHostException::class);

        app(PrintBoxOfficeZplHandler::class)->handle(new PrintBoxOfficeZplDTO(
            event_id: $event->id,
            attendee_public_id: $attendee->public_id,
            agent_user_id: $user->id,
            printer_host: '8.8.8.8',
        ));
    }

    public function test_printer_failure_does_not_record_print_job(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->once()->andThrow(new ZebraPrinterUnreachableException('down'));
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        try {
            app(PrintBoxOfficeZplHandler::class)->handle(new PrintBoxOfficeZplDTO(
                event_id: $event->id,
                attendee_public_id: $attendee->public_id,
                agent_user_id: $user->id,
                printer_host: '10.0.0.12',
            ));
            self::fail('expected printer failure');
        } catch (ZebraPrinterUnreachableException) {
        }

        self::assertSame(0, DB::table('print_jobs')->where('attendee_id', $attendee->id)->count());
    }
}
