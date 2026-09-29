<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\PrintBoxOfficeZplDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeContextHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintBoxOfficeZplHandler;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficePrinterPreferenceTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function fakePrinter(bool $reachable = true): void
    {
        $printer = Mockery::mock(ZebraPrinterClientInterface::class);
        $expectation = $printer->shouldReceive('send');
        if (! $reachable) {
            $expectation->andThrow(new ZebraPrinterUnreachableException('down'));
        }
        $this->app->instance(ZebraPrinterClientInterface::class, $printer);
    }

    private function print(int $eventId, string $attendeePublicId, int $userId, string $host): void
    {
        app(PrintBoxOfficeZplHandler::class)->handle(new PrintBoxOfficeZplDTO(
            event_id: $eventId,
            attendee_public_id: $attendeePublicId,
            agent_user_id: $userId,
            printer_host: $host,
        ));
    }

    private function lastHostInContext(int $userId, int $accountId, int $eventId): ?string
    {
        $events = app(GetBoxOfficeContextHandler::class)
            ->handle($userId, Role::BOX_OFFICE_OPERATOR->name, $accountId);

        return $events->firstWhere('id', $eventId)?->last_printer_host;
    }

    public function test_successful_print_remembers_host_for_user_and_event(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, 'secret-password');
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $this->fakePrinter();

        $this->print($event->id, $attendee->public_id, $operator->id, '192.168.1.50');

        self::assertSame('192.168.1.50', $this->lastHostInContext($operator->id, $event->account_id, $event->id));
    }

    public function test_unreachable_printer_is_not_remembered(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, 'secret-password');
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $this->fakePrinter(reachable: false);

        try {
            $this->print($event->id, $attendee->public_id, $operator->id, '10.0.0.12');
            self::fail('expected printer failure');
        } catch (ZebraPrinterUnreachableException) {
        }

        self::assertNull($this->lastHostInContext($operator->id, $event->account_id, $event->id));
        self::assertSame(0, DB::table('box_office_printer_preferences')->where('user_id', $operator->id)->count());
    }

    public function test_new_working_host_replaces_previous_one(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, 'secret-password');
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $this->fakePrinter();

        $this->print($event->id, $attendee->public_id, $operator->id, '192.168.1.50');
        $this->print($event->id, $attendee->public_id, $operator->id, '192.168.1.77');

        self::assertSame('192.168.1.77', $this->lastHostInContext($operator->id, $event->account_id, $event->id));
        self::assertSame(1, DB::table('box_office_printer_preferences')
            ->where(['user_id' => $operator->id, 'event_id' => $event->id])
            ->count());
    }

    public function test_failed_retry_keeps_previous_working_host(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, 'secret-password');
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $this->fakePrinter();
        $this->print($event->id, $attendee->public_id, $operator->id, '192.168.1.50');

        $this->fakePrinter(reachable: false);
        try {
            $this->print($event->id, $attendee->public_id, $operator->id, '192.168.1.99');
            self::fail('expected printer failure');
        } catch (ZebraPrinterUnreachableException) {
        }

        self::assertSame('192.168.1.50', $this->lastHostInContext($operator->id, $event->account_id, $event->id));
    }

    public function test_host_is_not_shared_with_another_operator_on_same_event(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $operatorA = $this->makeBoxOfficeOperator($event, 'secret-password');
        $operatorB = $this->makeBoxOfficeOperator($event, 'secret-password');
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $this->fakePrinter();

        $this->print($event->id, $attendee->public_id, $operatorA->id, '192.168.1.50');

        self::assertNull($this->lastHostInContext($operatorB->id, $event->account_id, $event->id));
    }

    public function test_host_is_scoped_to_the_event_it_printed_for(): void
    {
        [$eventA, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        [$eventB] = $this->createEventWithProductOnAccount($eventA->account_id);
        $operator = $this->makeBoxOfficeOperator($eventA, 'secret-password');
        $this->assignOperatorToEvent($operator, $eventB);
        $attendee = $this->createAttendeeViaHandler($eventA->id, $product->id, $productPrice->id);
        $this->fakePrinter();

        $this->print($eventA->id, $attendee->public_id, $operator->id, '192.168.1.50');

        self::assertSame('192.168.1.50', $this->lastHostInContext($operator->id, $eventA->account_id, $eventA->id));
        self::assertNull($this->lastHostInContext($operator->id, $eventA->account_id, $eventB->id));
    }
}
