<?php

namespace Tests\Unit\Services\Application\Handlers\Organizer;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\SubscribeToOrganizerNewsletterDTO;
use HiEvents\Services\Application\Handlers\Organizer\SubscribeToOrganizerNewsletterHandler;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Tests\TestCase;

class SubscribeToOrganizerNewsletterHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private EventRepositoryInterface|MockInterface $eventRepository;
    private OrganizerNewsletterSubscriberRepositoryInterface|MockInterface $subscriberRepository;
    private SubscribeToOrganizerNewsletterHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->subscriberRepository = Mockery::mock(OrganizerNewsletterSubscriberRepositoryInterface::class);

        $purifier = Mockery::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(fn(?string $value) => $value);

        $this->handler = new SubscribeToOrganizerNewsletterHandler(
            $this->eventRepository,
            $this->subscriberRepository,
            $purifier,
        );
    }

    public function test_stores_the_subscriber_with_a_timestamped_consent(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->with(14)->andReturn($this->event());

        $this->subscriberRepository->shouldReceive('createIfNotSubscribed')
            ->once()
            ->withArgs(function (array $attributes) {
                return $attributes['organizer_id'] === 8
                    && $attributes['event_id'] === 14
                    && $attributes['email'] === 'jo@example.com'
                    && $attributes['consent_text'] === "J'accepte de recevoir les actualités."
                    && $attributes['ip'] === '203.0.113.7'
                    && $attributes['user_agent'] === 'Mozilla/5.0'
                    && $attributes['consented_at'] !== null;
            })
            ->andReturnTrue();

        $this->assertTrue($this->handler->handle($this->dto(email: '  Jo@Example.com ')));
    }

    public function test_a_second_subscription_is_ignored_without_error(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->andReturn($this->event());
        $this->subscriberRepository->shouldReceive('createIfNotSubscribed')->once()->andReturnFalse();

        $this->assertFalse($this->handler->handle($this->dto()));
    }

    public function test_falls_back_to_a_server_consent_text(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->andReturn($this->event());
        $this->subscriberRepository->shouldReceive('createIfNotSubscribed')
            ->once()
            ->withArgs(fn(array $attributes) => str_contains($attributes['consent_text'], 'Mayotte La 1ère'))
            ->andReturnTrue();

        $this->handler->handle($this->dto(consentText: '<b></b>'));
    }

    public function test_refuses_when_the_rich_footer_is_disabled(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->andReturn($this->event(richFooter: false));
        $this->subscriberRepository->shouldNotReceive('createIfNotSubscribed');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle($this->dto());
    }

    public function test_refuses_when_the_newsletter_block_is_disabled(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->andReturn($this->event(newsletter: false));
        $this->subscriberRepository->shouldNotReceive('createIfNotSubscribed');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle($this->dto());
    }

    public function test_refuses_for_a_draft_or_unknown_event(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->with(14)->andReturn($this->event(status: EventStatus::DRAFT->name));
        $this->eventRepository->shouldReceive('findFirst')->with(99)->andReturnNull();
        $this->subscriberRepository->shouldNotReceive('createIfNotSubscribed');

        foreach ([14, 99] as $eventId) {
            try {
                $this->handler->handle($this->dto(eventId: $eventId));
                $this->fail('An exception was expected');
            } catch (ResourceNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function dto(
        int     $eventId = 14,
        string  $email = 'jo@example.com',
        ?string $consentText = "J'accepte de recevoir les actualités.",
    ): SubscribeToOrganizerNewsletterDTO
    {
        return new SubscribeToOrganizerNewsletterDTO(
            event_id: $eventId,
            email: $email,
            consent_text: $consentText,
            ip: '203.0.113.7',
            user_agent: 'Mozilla/5.0',
        );
    }

    private function event(bool $richFooter = true, bool $newsletter = true, string $status = 'LIVE'): EventDomainObject
    {
        return (new EventDomainObject())
            ->setId(14)
            ->setOrganizerId(8)
            ->setStatus($status)
            ->setOrganizer((new OrganizerDomainObject())->setId(8)->setName('Mayotte La 1ère'))
            ->setEventSettings(
                (new EventSettingDomainObject())
                    ->setRichFooterEnabled($richFooter)
                    ->setHomepageFooterSettings(['newsletter_enabled' => $newsletter])
            );
    }
}
