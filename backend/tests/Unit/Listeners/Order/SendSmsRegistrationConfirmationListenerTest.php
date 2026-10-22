<?php

namespace Tests\Unit\Listeners\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Sms\SendSmsRegistrationConfirmationJob;
use HiEvents\Listeners\Order\SendSmsRegistrationConfirmationListener;
use HiEvents\Services\Domain\Sms\RegistrationConfirmation\SmsRegistrationConfirmationService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

class SendSmsRegistrationConfirmationListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private SmsRegistrationConfirmationService|MockInterface $service;

    private SendSmsRegistrationConfirmationListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->service = Mockery::mock(SmsRegistrationConfirmationService::class);
        $this->listener = new SendSmsRegistrationConfirmationListener($this->service, new NullLogger);
    }

    public function test_queues_the_confirmation_of_a_completed_online_order(): void
    {
        $this->service->shouldReceive('isEnabledForEvent')->with(8)->andReturnTrue();

        $this->listener->handle(new OrderStatusChangedEvent($this->order()));

        Bus::assertDispatched(SendSmsRegistrationConfirmationJob::class, fn (SendSmsRegistrationConfirmationJob $job) => $job->orderId === 40);
    }

    public function test_nothing_is_queued_when_the_option_is_disabled(): void
    {
        $this->service->shouldReceive('isEnabledForEvent')->andReturnFalse();

        $this->listener->handle(new OrderStatusChangedEvent($this->order()));

        Bus::assertNotDispatched(SendSmsRegistrationConfirmationJob::class);
    }

    public function test_box_office_manual_and_pending_orders_are_ignored(): void
    {
        $this->service->shouldNotReceive('isEnabledForEvent');

        $this->listener->handle(new OrderStatusChangedEvent($this->order()->setIsManuallyCreated(true)));
        $this->listener->handle(new OrderStatusChangedEvent($this->order()->setStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name)));

        Bus::assertNotDispatched(SendSmsRegistrationConfirmationJob::class);
    }

    public function test_an_error_never_blocks_the_order(): void
    {
        $this->service->shouldReceive('isEnabledForEvent')->andThrow(new RuntimeException('Base indisponible'));

        $this->listener->handle(new OrderStatusChangedEvent($this->order()));

        Bus::assertNotDispatched(SendSmsRegistrationConfirmationJob::class);
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(40)
            ->setEventId(8)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setIsManuallyCreated(false);
    }
}
