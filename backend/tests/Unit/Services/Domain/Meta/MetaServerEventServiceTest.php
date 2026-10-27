<?php

namespace Tests\Unit\Services\Domain\Meta;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\Meta\DTO\MetaServerEventDTO;
use HiEvents\Services\Domain\Meta\MetaServerEventService;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class MetaServerEventServiceTest extends TestCase
{
    use FakesPublicBaseUrl;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private OrganizerSettingsRepositoryInterface|MockInterface $settingsRepository;

    private MetaConversionsApiClient|MockInterface $client;

    private MetaServerEventService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->client = Mockery::mock(MetaConversionsApiClient::class);
        $this->fakePublicBaseUrl();

        $this->eventRepository->shouldReceive('findFirst')->with(3)
            ->andReturn((new EventDomainObject)->setId(3)->setOrganizerId(8)->setTitle('Gala'));

        $this->service = new MetaServerEventService(
            $this->eventRepository,
            $this->settingsRepository,
            $this->client,
            new NullLogger,
        );
    }

    public function test_sends_view_content_with_the_pixel_event_id(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());

        $this->client->shouldReceive('sendEvents')->once()->withArgs(
            fn (string $pixelId, string $token, array $events, ?string $testCode) => $pixelId === '1234567890'
                && $token === 'EAAtoken1234567890abcdef'
                && $testCode === null
                && $events[0]['event_name'] === 'ViewContent'
                && $events[0]['event_id'] === 'vc_abcdef123'
                && $events[0]['event_source_url'] === 'https://ticket.picha.fr/event/3/gala?fbclid=x'
                && $events[0]['user_data'] === [
                    'client_ip_address' => '1.2.3.4',
                    'client_user_agent' => 'Mozilla/5.0',
                    'fbp' => 'fb.1.1700000000000.1',
                    'fbc' => 'fb.1.1700000000000.x',
                ]
                && $events[0]['custom_data']['content_ids'] === ['3']
        )->andReturn(1);

        $this->assertTrue($this->service->send($this->dto('ViewContent', consent: true)));
    }

    public function test_page_view_has_no_custom_data_and_falls_back_to_the_event_url(): void
    {
        $payload = $this->service->build(new MetaServerEventDTO(
            eventId: 3,
            eventName: 'PageView',
            metaEventId: 'pv_abcdef123',
            eventTime: 1_700_000_000,
            consent: true,
        ), 'https://ticket.picha.fr/event/3/gala');

        $this->assertArrayNotHasKey('custom_data', $payload);
        $this->assertSame('https://ticket.picha.fr/event/3/gala', $payload['event_source_url']);
        $this->assertSame('website', $payload['action_source']);
    }

    public function test_skips_without_consent_by_default(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());

        $this->client->shouldNotReceive('sendEvents');

        $this->assertFalse($this->service->isAllowed(3, false));
        $this->assertFalse($this->service->send($this->dto('PageView', consent: false)));
    }

    public function test_sends_without_consent_when_the_organizer_enabled_it_but_never_the_cookies(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->settings()->setMetaCapiSendWithoutConsent(true));

        $this->client->shouldReceive('sendEvents')->once()->withArgs(
            fn (string $pixelId, string $token, array $events) => ! isset($events[0]['user_data']['fbp'])
                && ! isset($events[0]['user_data']['fbc'])
                && $events[0]['user_data']['client_ip_address'] === '1.2.3.4'
        )->andReturn(1);

        $this->assertTrue($this->service->send($this->dto('PageView', consent: false)));
    }

    public function test_skips_when_conversions_api_is_not_configured_or_event_is_unknown(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->settings()->setMetaConversionsApiToken(null));
        $this->eventRepository->shouldReceive('findFirst')->with(404)->andReturnNull();

        $this->client->shouldNotReceive('sendEvents');

        $this->assertFalse($this->service->send($this->dto('ViewContent', consent: true)));
        $this->assertFalse($this->service->isAllowed(404, true));
    }

    public function test_rejects_unknown_event_names(): void
    {
        $this->client->shouldNotReceive('sendEvents');

        $this->assertFalse($this->service->send($this->dto('Purchase', consent: true)));
    }

    public function test_passes_the_test_event_code(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->settings()->setMetaTestEventCode('TEST123'));

        $this->client->shouldReceive('sendEvents')->once()->withArgs(
            fn (string $pixelId, string $token, array $events, ?string $testCode) => $testCode === 'TEST123'
        )->andReturn(1);

        $this->assertTrue($this->service->send($this->dto('InitiateCheckout', consent: true)));
    }

    private function dto(string $eventName, bool $consent): MetaServerEventDTO
    {
        return new MetaServerEventDTO(
            eventId: 3,
            eventName: $eventName,
            metaEventId: 'vc_abcdef123',
            eventTime: 1_700_000_000,
            consent: $consent,
            eventSourceUrl: 'https://ticket.picha.fr/event/3/gala?fbclid=x',
            clientIpAddress: '1.2.3.4',
            clientUserAgent: 'Mozilla/5.0',
            fbp: 'fb.1.1700000000000.1',
            fbc: 'fb.1.1700000000000.x',
        );
    }

    private function settings(): OrganizerSettingDomainObject
    {
        return (new OrganizerSettingDomainObject)
            ->setOrganizerId(8)
            ->setMetaConversionsApiToken('EAAtoken1234567890abcdef')
            ->setTrackingPixels([
                ['provider' => 'facebook_pixel', 'pixel_id' => '1234567890', 'enabled' => true],
            ]);
    }
}
