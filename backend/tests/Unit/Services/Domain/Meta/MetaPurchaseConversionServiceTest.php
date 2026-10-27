<?php

namespace Tests\Unit\Services\Domain\Meta;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Meta\MetaPurchaseConversionService;
use HiEvents\Services\Domain\Meta\MetaPurchaseEventBuilder;
use HiEvents\Services\Domain\Meta\MetaUserDataHasher;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class MetaPurchaseConversionServiceTest extends TestCase
{
    use FakesPublicBaseUrl;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private OrganizerSettingsRepositoryInterface|MockInterface $settingsRepository;

    private MetaConversionsApiClient|MockInterface $client;

    private SmsCustomerDirectoryRepositoryInterface|MockInterface $directoryRepository;

    private MetaPurchaseConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->client = Mockery::mock(MetaConversionsApiClient::class);
        $this->directoryRepository = Mockery::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $this->directoryRepository->shouldReceive('findActiveAttendeesWithPhone')->andReturn(collect())->byDefault();
        $this->fakePublicBaseUrl();

        $this->service = new MetaPurchaseConversionService(
            $this->orderRepository,
            $this->eventRepository,
            $this->settingsRepository,
            new MetaPurchaseEventBuilder(new MetaUserDataHasher),
            $this->client,
            new NullLogger,
            $this->directoryRepository,
        );
    }

    public function test_eligibility_requires_completed_online_order_not_yet_sent(): void
    {
        $this->assertTrue(MetaPurchaseConversionService::isEligible($this->order()));
        $this->assertTrue(MetaPurchaseConversionService::isEligible($this->order()->setTrackingContext(null)));
        $this->assertFalse(MetaPurchaseConversionService::isEligible($this->order()->setStatus(OrderStatus::RESERVED->name)));
        $this->assertFalse(MetaPurchaseConversionService::isEligible($this->order()->setStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name)));
        $this->assertFalse(MetaPurchaseConversionService::isEligible($this->order()->setIsManuallyCreated(true)));
        $this->assertFalse(MetaPurchaseConversionService::isEligible($this->order()->setMetaCapiSentAt('2026-10-13 10:00:00')));
    }

    public function test_skips_purchase_without_consent_by_default(): void
    {
        $this->mockOrderLookup($this->order()->setTrackingContext(['consent' => false]));
        $this->eventRepository->shouldReceive('findById')->andReturn((new EventDomainObject)->setId(3)->setOrganizerId(8));
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());

        $this->client->shouldNotReceive('sendEvents');
        $this->orderRepository->shouldNotReceive('updateFromArray');

        $this->assertFalse($this->service->sendPurchase(77));
    }

    public function test_sends_purchase_without_consent_when_the_organizer_enabled_it(): void
    {
        $this->mockOrderLookup($this->order()->setTrackingContext(null));
        $this->eventRepository->shouldReceive('findById')
            ->andReturn((new EventDomainObject)->setId(3)->setOrganizerId(8)->setTitle('Gala'));
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->settings()->setMetaCapiSendWithoutConsent(true));

        $this->client->shouldReceive('sendEvents')->once()->withArgs(
            fn (string $pixelId, string $token, array $events) => $events[0]['user_data']['em'] === [hash('sha256', 'buyer@example.com')]
                && ! isset($events[0]['user_data']['fbp'])
        )->andReturn(1);
        $this->orderRepository->shouldReceive('updateFromArray')->once()->andReturn($this->order());

        $this->assertTrue($this->service->sendPurchase(77));
    }

    public function test_sends_hashed_phone_of_the_order(): void
    {
        $this->mockOrderLookup($this->order());
        $this->eventRepository->shouldReceive('findById')
            ->andReturn((new EventDomainObject)->setId(3)->setOrganizerId(8)->setTitle('Gala'));
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());
        $this->directoryRepository->shouldReceive('findActiveAttendeesWithPhone')->with(3, 77)->andReturn(collect([
            new SmsReminderAttendeeDTO(attendeeId: 1, phone: null, firstName: 'A', lastName: 'B'),
            new SmsReminderAttendeeDTO(attendeeId: 2, phone: '06 39 25 02 06', firstName: 'C', lastName: 'D'),
        ]));

        $this->client->shouldReceive('sendEvents')->once()->withArgs(
            fn (string $pixelId, string $token, array $events) => $events[0]['user_data']['ph'] === [hash('sha256', '262639250206')]
        )->andReturn(1);
        $this->orderRepository->shouldReceive('updateFromArray')->once()->andReturn($this->order());

        $this->assertTrue($this->service->sendPurchase(77));
    }

    public function test_sends_purchase_and_marks_order(): void
    {
        $this->mockOrderLookup($this->order());
        $this->eventRepository->shouldReceive('findById')->with(3)
            ->andReturn((new EventDomainObject)->setId(3)->setOrganizerId(8)->setTitle('Gala'));
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());

        $this->client->shouldReceive('sendEvents')->once()->withArgs(
            fn (string $pixelId, string $token, array $events, ?string $testCode) => $pixelId === '1234567890'
                && $token === 'EAAtoken1234567890abcdef'
                && $events[0]['event_id'] === 'order_o_123'
                && $testCode === 'TEST123'
        )->andReturn(1);

        $this->orderRepository->shouldReceive('updateFromArray')->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 77 && isset($attributes['meta_capi_sent_at']))
            ->andReturn($this->order());

        $this->assertTrue($this->service->sendPurchase(77));
    }

    public function test_skips_when_organizer_has_no_conversions_api_token(): void
    {
        $this->mockOrderLookup($this->order());
        $this->eventRepository->shouldReceive('findById')->andReturn((new EventDomainObject)->setId(3)->setOrganizerId(8));
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->settings()->setMetaConversionsApiToken(null));

        $this->client->shouldNotReceive('sendEvents');
        $this->orderRepository->shouldNotReceive('updateFromArray');

        $this->assertFalse($this->service->sendPurchase(77));
    }

    public function test_skips_when_facebook_pixel_is_disabled(): void
    {
        $this->mockOrderLookup($this->order());
        $this->eventRepository->shouldReceive('findById')->andReturn((new EventDomainObject)->setId(3)->setOrganizerId(8));
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings()->setTrackingPixels([
            ['provider' => 'facebook_pixel', 'pixel_id' => '1234567890', 'enabled' => false],
        ]));

        $this->client->shouldNotReceive('sendEvents');

        $this->assertFalse($this->service->sendPurchase(77));
    }

    private function mockOrderLookup(OrderDomainObject $order): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirst')->with(77)->andReturn($order);
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(77)
            ->setEventId(3)
            ->setShortId('o_123')
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setCurrency('EUR')
            ->setTotalGross(20)
            ->setEmail('buyer@example.com')
            ->setTrackingContext(['consent' => true, 'fbp' => 'fb.1.1700000000000.1']);
    }

    private function settings(): OrganizerSettingDomainObject
    {
        return (new OrganizerSettingDomainObject)
            ->setOrganizerId(8)
            ->setMetaConversionsApiToken('EAAtoken1234567890abcdef')
            ->setMetaTestEventCode('TEST123')
            ->setTrackingPixels([
                ['provider' => 'google_analytics_4', 'pixel_id' => 'G-ABCDEF', 'enabled' => true],
                ['provider' => 'facebook_pixel', 'pixel_id' => '1234567890', 'enabled' => true],
            ]);
    }
}
