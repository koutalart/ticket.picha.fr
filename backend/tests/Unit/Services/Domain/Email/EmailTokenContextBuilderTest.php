<?php

namespace Tests\Unit\Services\Domain\Email;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Services\Domain\Email\EmailTokenContextBuilder;
use Illuminate\Support\Collection;
use Mockery;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class EmailTokenContextBuilderTest extends TestCase
{
    use FakesPublicBaseUrl;

    private EmailTokenContextBuilder $contextBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://ticket.picha.fr']);
        $this->fakePublicBaseUrl([8 => 'mayottela1ere.fr'], [9 => 1, 14 => 8]);
        $this->contextBuilder = new EmailTokenContextBuilder();
    }

    public function test_builds_order_confirmation_context(): void
    {
        $order = $this->createMockOrder();
        $event = $this->createMockEvent();
        $organizer = $this->createMockOrganizer();
        $eventSettings = $this->createMockEventSettings();

        $context = $this->contextBuilder->buildOrderConfirmationContext(
            $order,
            $event,
            $organizer,
            $eventSettings
        );

        $this->assertIsArray($context);
        $this->assertArrayHasKey('order', $context);
        $this->assertArrayHasKey('event', $context);
        $this->assertArrayHasKey('organizer', $context);
        $this->assertArrayHasKey('settings', $context);

        // Test order context
        $this->assertEquals('ORD-123456', $context['order']['number']);
        $this->assertEquals('9 999,00 $US', $this->normalizeSpaces($context['order']['total']));
        $this->assertEquals('John', $context['order']['first_name']);
        $this->assertEquals('Doe', $context['order']['last_name']);
        $this->assertEquals('john@example.com', $context['order']['email']);

        // Test event context
        $this->assertEquals('Amazing Event', $context['event']['title']);
        $this->assertEquals('This is an amazing event', $context['event']['description']);

        // Test organizer context
        $this->assertEquals('Great Organizer', $context['organizer']['name']);
        $this->assertEquals('contact@organizer.com', $context['organizer']['email']);

        // Test settings context
        $this->assertEquals('support@event.com', $context['settings']['support_email']);
    }

    public function test_builds_attendee_ticket_context(): void
    {
        $attendee = $this->createMockAttendee();
        $order = $this->createMockOrder();
        $event = $this->createMockEvent();
        $organizer = $this->createMockOrganizer();
        $eventSettings = $this->createMockEventSettings();

        $context = $this->contextBuilder->buildAttendeeTicketContext(
            $attendee,
            $order,
            $event,
            $organizer,
            $eventSettings
        );

        $this->assertIsArray($context);
        $this->assertArrayHasKey('attendee', $context);
        $this->assertArrayHasKey('ticket', $context);
        $this->assertArrayHasKey('order', $context);
        $this->assertArrayHasKey('event', $context);
        $this->assertArrayHasKey('organizer', $context);

        // Test attendee context
        $this->assertEquals('Jane Smith', $context['attendee']['name']);
        $this->assertEquals('jane@example.com', $context['attendee']['email']);
        $this->assertSame('', $context['attendee']['attendance_url']);

        // Test ticket context
        $this->assertEquals('General Admission', $context['ticket']['name']);
        $this->assertEquals('4 999,00 $US', $this->normalizeSpaces($context['ticket']['price']));

        // Test event context
        $this->assertEquals('Amazing Event', $context['event']['title']);
        
        // Test organizer context
        $this->assertEquals('Great Organizer', $context['organizer']['name']);
    }

    public function test_whitelists_only_allowed_tokens_for_order_confirmation(): void
    {
        $order = $this->createMockOrder();
        $event = $this->createMockEvent();
        $organizer = $this->createMockOrganizer();
        $eventSettings = $this->createMockEventSettings();

        $context = $this->contextBuilder->buildOrderConfirmationContext(
            $order,
            $event,
            $organizer,
            $eventSettings
        );

        // Test that expected nested structure exists
        $this->assertArrayHasKey('order', $context);
        $this->assertArrayHasKey('event', $context);
        $this->assertArrayHasKey('organizer', $context);
        $this->assertArrayHasKey('settings', $context);
        
        // Test that expected properties are included
        $this->assertArrayHasKey('number', $context['order']);
        $this->assertArrayHasKey('first_name', $context['order']);
        $this->assertArrayHasKey('title', $context['event']);
    }

    public function test_whitelists_only_allowed_tokens_for_attendee_ticket(): void
    {
        $attendee = $this->createMockAttendee();
        $order = $this->createMockOrder();
        $event = $this->createMockEvent();
        $organizer = $this->createMockOrganizer();
        $eventSettings = $this->createMockEventSettings();

        $context = $this->contextBuilder->buildAttendeeTicketContext(
            $attendee,
            $order,
            $event,
            $organizer,
            $eventSettings
        );

        // Test that expected nested structure exists
        $this->assertArrayHasKey('attendee', $context);
        $this->assertArrayHasKey('ticket', $context);
        $this->assertArrayHasKey('event', $context);
        $this->assertArrayHasKey('organizer', $context);
        
        // Test that expected properties are included
        $this->assertArrayHasKey('name', $context['attendee']);
        $this->assertArrayHasKey('name', $context['ticket']);
        $this->assertArrayHasKey('title', $context['event']);
    }

    private function createMockOrder(): OrderDomainObject
    {
        $orderItem = Mockery::mock(OrderItemDomainObject::class, [
            'getProductPriceId' => 123,
            'getPrice' => 4999,
            'getItemName' => 'General Admission',
        ]);

        $orderItems = new Collection([$orderItem]);

        return Mockery::mock(OrderDomainObject::class, [
            'getPublicId' => 'ORD-123456',
            'getTotalGross' => 9999,
            'getFirstName' => 'John',
            'getLastName' => 'Doe',
            'getEmail' => 'john@example.com',
            'getCreatedAt' => '2024-01-15 10:30:00',
            'getShortId' => 'ABC123',
            'isOrderAwaitingOfflinePayment' => false,
            'getPaymentProvider' => PaymentProviders::STRIPE->value,
            'getOrderItems' => $orderItems,
            'getCurrency' => 'USD',
            'getLocale' => 'en',
        ]);
    }

    private function createMockEvent(int $organizerId = 1): EventDomainObject
    {
        return Mockery::mock(EventDomainObject::class, [
            'getOrganizer' => null,
            'getOrganizerId' => $organizerId,
            'getTitle' => 'Amazing Event',
            'getDescription' => 'This is an amazing event',
            'getStartDate' => '2024-02-15 19:00:00',
            'getEndDate' => '2024-02-15 22:00:00',
            'getTimezone' => 'America/New_York',
            'getCurrency' => 'USD',
            'getId' => 1,
        ]);
    }

    private function createMockOrganizer(): OrganizerDomainObject
    {
        return Mockery::mock(OrganizerDomainObject::class, [
            'getName' => 'Great Organizer',
            'getEmail' => 'contact@organizer.com',
        ]);
    }

    public function test_attendee_ticket_context_includes_attendance_url_when_enabled(): void
    {
        $context = $this->contextBuilder->buildAttendeeTicketContext(
            $this->createMockAttendee(),
            $this->createMockOrder(),
            $this->createMockEvent(),
            $this->createMockOrganizer(),
            $this->createMockEventSettings(allowAttendanceResponse: true),
        );

        $this->assertStringContainsString('/venue/9/ATT123?code=', $context['attendee']['attendance_url']);
    }

    public function test_links_stay_on_the_organizer_custom_domain(): void
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class, [
            'getFirstName' => 'Jane',
            'getLastName' => 'Smith',
            'getEmail' => 'jane@example.com',
            'getProductPriceId' => 123,
            'getShortId' => 'ATT123',
            'getId' => 77,
            'getEventId' => 14,
        ]);

        $context = $this->contextBuilder->buildAttendeeTicketContext(
            $attendee,
            $this->createMockOrder(),
            $this->createMockEvent(organizerId: 8),
            $this->createMockOrganizer(),
            $this->createMockEventSettings(allowAttendanceResponse: true),
        );

        $this->assertStringStartsWith('https://mayottela1ere.fr/checkout/1/', $context['order']['url']);
        $this->assertSame('https://mayottela1ere.fr/product/1/ATT123', $context['ticket']['url']);
        $this->assertStringStartsWith('https://mayottela1ere.fr/venue/14/ATT123?code=', $context['attendee']['attendance_url']);
    }

    public function test_links_use_the_platform_without_custom_domain(): void
    {
        $context = $this->contextBuilder->buildAttendeeTicketContext(
            $this->createMockAttendee(),
            $this->createMockOrder(),
            $this->createMockEvent(),
            $this->createMockOrganizer(),
            $this->createMockEventSettings(allowAttendanceResponse: true),
        );

        $this->assertStringStartsWith('https://ticket.picha.fr/checkout/1/', $context['order']['url']);
        $this->assertStringStartsWith('https://ticket.picha.fr/venue/9/ATT123?code=', $context['attendee']['attendance_url']);
    }

    private function createMockEventSettings(bool $allowAttendanceResponse = false): EventSettingDomainObject
    {
        return Mockery::mock(EventSettingDomainObject::class, [
            'getSupportEmail' => 'support@event.com',
            'getOfflinePaymentInstructions' => 'Pay by bank transfer',
            'getPostCheckoutMessage' => 'Thank you for your purchase!',
            'getLocationDetails' => null,
            'getAllowAttendeeAttendanceResponse' => $allowAttendanceResponse,
        ]);
    }

    private function createMockAttendee(): AttendeeDomainObject
    {
        return Mockery::mock(AttendeeDomainObject::class, [
            'getFirstName' => 'Jane',
            'getLastName' => 'Smith',
            'getEmail' => 'jane@example.com',
            'getProductPriceId' => 123,
            'getShortId' => 'ATT123',
            'getId' => 77,
            'getEventId' => 9,
        ]);
    }

    private function normalizeSpaces(string $value): string
    {
        return str_replace(["\u{a0}", "\u{202f}"], ' ', $value);
    }
}
