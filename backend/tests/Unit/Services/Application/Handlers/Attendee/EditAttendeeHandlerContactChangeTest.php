<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Jobs\Attendee\SendTicketAfterAttendeeContactChangeJob;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\EditAttendeeDTO;
use HiEvents\Services\Application\Handlers\Attendee\EditAttendeeHandler;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Mockery as m;
use Tests\TestCase;

class EditAttendeeHandlerContactChangeTest extends TestCase
{
    private function attendee(string $firstName, string $email, ?string $phone): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId(3944)->setEventId(14)->setProductId(27)->setProductPriceId(30)
            ->setStatus(AttendeeStatus::ACTIVE->name)
            ->setFirstName($firstName)->setLastName('')->setEmail($email)->setPhone($phone);
    }

    private function handle(AttendeeDomainObject $before, AttendeeDomainObject $after, ?string $phone): void
    {
        $attendees = m::mock(AttendeeRepositoryInterface::class);
        $attendees->shouldReceive('findFirstWhere')->andReturn($before);
        $attendees->shouldReceive('updateByIdWhere')->andReturn($after);

        $product = (new ProductDomainObject)->setId(27)->setEventId(14)
            ->setProductPrices(collect([(new ProductPriceDomainObject)->setId(30)]));
        $products = m::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('loadRelation')->andReturnSelf();
        $products->shouldReceive('findFirstWhere')->andReturn($product);

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $events = m::mock(DomainEventDispatcherService::class);
        $events->shouldReceive('dispatch');

        (new EditAttendeeHandler($attendees, $products, m::mock(ProductQuantityUpdateService::class), $db, $events))
            ->handle(new EditAttendeeDTO(
                first_name: $after->getFirstName(), last_name: '', email: $after->getEmail(),
                product_id: 27, product_price_id: 30, event_id: 14, attendee_id: 3944, phone: $phone,
            ));
    }

    public function test_ticket_goes_to_new_contact_when_name_and_email_change(): void
    {
        Bus::fake();

        $this->handle(
            $this->attendee('Ben', 'jo@example.com', null),
            $this->attendee('El Djine', 'eldjine@example.com', '0639123456'),
            '0639123456',
        );

        Bus::assertDispatched(SendTicketAfterAttendeeContactChangeJob::class, fn ($job) => $job->attendeeId === 3944
            && $job->sendEmail && $job->sendSms);
    }

    public function test_nothing_sent_when_contact_unchanged(): void
    {
        Bus::fake();

        $this->handle(
            $this->attendee('Ben', 'jo@example.com', null),
            $this->attendee('Ben', 'jo@example.com', null),
            null,
        );

        Bus::assertNotDispatched(SendTicketAfterAttendeeContactChangeJob::class);
    }
}
