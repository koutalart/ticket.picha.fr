<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeZplPrintTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    public function test_operator_can_silently_print_on_lan_zebra(): void
    {
        [$event, $product, $productPrice, $admin] = $this->createEventWithProduct(
            price: 25.00,
            userPassword: self::PASSWORD,
        );
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->once()->with('192.168.10.20', 9100, Mockery::type('string'));
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        $this->postJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/print-zpl",
            ['printer_host' => '192.168.10.20'],
            ['Authorization' => 'Bearer '.$token],
        )->assertNoContent();
    }

    public function test_station_label_format_keeps_the_8_by_8_ticket(): void
    {
        [$event, $product, $productPrice, $admin] = $this->createEventWithProduct(
            price: 25.00,
            userPassword: self::PASSWORD,
        );
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->once()->with(
            '192.168.10.20',
            9100,
            Mockery::on(fn (string $zpl) => str_contains($zpl, "^PW639\n") && str_contains($zpl, "^LL639\n")),
        );
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        $this->postJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/print-zpl",
            [
                'printer_host' => '192.168.10.20',
                'printer_dpi' => 203,
                'label_width_mm' => 104,
                'label_length_mm' => 150,
            ],
            ['Authorization' => 'Bearer '.$token],
        )->assertNoContent();
    }

    public function test_unsupported_printer_dpi_is_rejected(): void
    {
        [$event, $product, $productPrice, $admin] = $this->createEventWithProduct(
            price: 25.00,
            userPassword: self::PASSWORD,
        );
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->never();
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        $this->postJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/print-zpl",
            ['printer_host' => '192.168.10.20', 'printer_dpi' => 250],
            ['Authorization' => 'Bearer '.$token],
        )->assertUnprocessable()->assertJsonValidationErrors('printer_dpi');
    }

    public function test_unreachable_printer_returns_message_for_agent(): void
    {
        [$event, $product, $productPrice, $admin] = $this->createEventWithProduct(
            price: 25.00,
            userPassword: self::PASSWORD,
        );
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->once()->andThrow(
            new ZebraPrinterUnreachableException("Imprimante injoignable — vérifiez l'adresse IP (192.168.10.20).")
        );
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        $this->postJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/print-zpl",
            ['printer_host' => '192.168.10.20'],
            ['Authorization' => 'Bearer '.$token],
        )
            ->assertStatus(502)
            ->assertJsonPath('message', "Imprimante injoignable — vérifiez l'adresse IP (192.168.10.20).");

        self::assertSame(0, DB::table('print_jobs')->where('attendee_id', $attendee->id)->count());
    }

    public function test_public_printer_host_is_rejected(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            userPassword: self::PASSWORD,
        );
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $printer->shouldReceive('send')->never();
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);

        $this->postJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/print-zpl",
            ['printer_host' => '1.1.1.1'],
            ['Authorization' => 'Bearer '.$token],
        )->assertUnprocessable();
    }

    public function test_other_account_cannot_print_zpl(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $other = $this->createUnrelatedOrganizerUser(self::PASSWORD);
        $token = $this->loginAndGetToken($other, self::PASSWORD);

        $this->postJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/print-zpl",
            ['printer_host' => '192.168.1.1'],
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(403);
    }
}
