<?php

namespace Tests\Unit\Services\Domain\Meta;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Jobs\Meta\SendMetaServerEventJob;
use HiEvents\Services\Application\Handlers\Meta\TrackMetaPageEventHandler;
use HiEvents\Services\Domain\Meta\MetaServerEventDispatcher;
use HiEvents\Services\Domain\Meta\MetaServerEventService;
use HiEvents\Services\Domain\Meta\OrderTrackingContextFactory;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

class MetaServerEventDispatcherTest extends TestCase
{
    private MetaServerEventService|MockInterface $service;

    private MetaServerEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->service = Mockery::mock(MetaServerEventService::class);
        $this->dispatcher = new MetaServerEventDispatcher($this->service, new NullLogger);
    }

    public function test_queues_initiate_checkout_with_the_checkout_event_id(): void
    {
        $this->service->shouldReceive('isAllowed')->with(3, true)->andReturnTrue();

        $queued = $this->dispatcher->queueInitiateCheckout($this->order(), [
            'consent' => true,
            'fbp' => 'fb.1.1700000000000.1',
            'fbclid' => 'IwAR_click',
            'client_ip_address' => '1.2.3.4',
            'client_user_agent' => 'Mozilla/5.0',
            'event_source_url' => 'https://ticket.picha.fr/event/3/gala',
        ], '9.9.9.9', 'Other');

        $this->assertTrue($queued);
        Bus::assertDispatched(SendMetaServerEventJob::class, function (SendMetaServerEventJob $job) {
            $event = $job->event;

            return $event->eventName === 'InitiateCheckout'
                && $event->metaEventId === 'checkout_o_123'
                && $event->eventId === 3
                && $event->consent === true
                && $event->value === 25.0
                && $event->currency === 'EUR'
                && $event->numItems === 3
                && $event->clientIpAddress === '1.2.3.4'
                && $event->fbp === 'fb.1.1700000000000.1'
                && str_ends_with((string) $event->fbc, '.IwAR_click')
                && $event->eventSourceUrl === 'https://ticket.picha.fr/event/3/gala';
        });
    }

    public function test_does_not_queue_when_not_allowed(): void
    {
        $this->service->shouldReceive('isAllowed')->with(3, false)->andReturnFalse();

        $this->assertFalse($this->dispatcher->queueInitiateCheckout($this->order(), null, '1.2.3.4', 'UA'));
        Bus::assertNotDispatched(SendMetaServerEventJob::class);
    }

    public function test_without_consent_uses_the_request_ip_and_never_advertising_identifiers(): void
    {
        $this->service->shouldReceive('isAllowed')->with(3, false)->andReturnTrue();

        $this->dispatcher->queueInitiateCheckout($this->order(), [
            'consent' => false,
            'fbp' => 'fb.1.1700000000000.1',
            'event_source_url' => 'https://ticket.picha.fr/event/3/gala?fbclid=x',
        ], '9.9.9.9', 'Mozilla/5.0');

        Bus::assertDispatched(SendMetaServerEventJob::class, fn (SendMetaServerEventJob $job) => $job->event->consent === false
            && $job->event->fbp === null
            && $job->event->fbc === null
            && $job->event->eventSourceUrl === null
            && $job->event->clientIpAddress === '9.9.9.9'
            && $job->event->clientUserAgent === 'Mozilla/5.0');
    }

    public function test_never_throws(): void
    {
        $this->service->shouldReceive('isAllowed')->andThrow(new RuntimeException('database down'));

        $this->assertFalse($this->dispatcher->queueInitiateCheckout($this->order(), null, '1.2.3.4', 'UA'));
        Bus::assertNotDispatched(SendMetaServerEventJob::class);
    }

    public function test_page_event_handler_sanitizes_the_browser_payload(): void
    {
        $this->service->shouldReceive('isAllowed')->with(3, true)->andReturnTrue();
        $handler = new TrackMetaPageEventHandler(new OrderTrackingContextFactory, $this->dispatcher);

        $handler->handle(3, 'ViewContent', 'vc_abcdef123', [
            'consent' => true,
            'event_source_url' => 'javascript:alert(1)',
            'fbp' => 'not-a-cookie',
            'fbc' => 'fb.1.1700000000000.IwAR',
            'fbclid' => null,
        ], '1.2.3.4', 'Mozilla/5.0');

        Bus::assertDispatched(SendMetaServerEventJob::class, fn (SendMetaServerEventJob $job) => $job->event->eventName === 'ViewContent'
            && $job->event->metaEventId === 'vc_abcdef123'
            && $job->event->eventSourceUrl === null
            && $job->event->fbp === null
            && $job->event->fbc === 'fb.1.1700000000000.IwAR'
            && $job->event->clientIpAddress === '1.2.3.4');
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(77)
            ->setEventId(3)
            ->setShortId('o_123')
            ->setCurrency('EUR')
            ->setTotalGross(25)
            ->setOrderItems(collect([
                (new OrderItemDomainObject)->setQuantity(2),
                (new OrderItemDomainObject)->setQuantity(1),
            ]));
    }
}
