<?php

namespace Tests\Unit\Services\Application\Handlers\EventFeedback;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventFeedbackDomainObject;
use HiEvents\Exceptions\EventFeedbackNotOpenException;
use HiEvents\Repository\Interfaces\EventFeedbackRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventFeedback\DTO\SubmitEventFeedbackDTO;
use HiEvents\Services\Application\Handlers\EventFeedback\SubmitEventFeedbackHandler;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery as m;
use Tests\TestCase;

class SubmitEventFeedbackHandlerTest extends TestCase
{
    private EventFeedbackRepositoryInterface $feedbackRepository;

    private EventRepositoryInterface $eventRepository;

    private SubmitEventFeedbackHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedbackRepository = m::mock(EventFeedbackRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $purifier = m::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(fn (?string $value) => $value === null ? null : preg_replace('#<script.*?</script>#is', '', $value));
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('services.feedback.event_ids', '')->andReturn('14, 20');
        $config->shouldReceive('get')->with('app.key')->andReturn('key');

        $this->handler = new SubmitEventFeedbackHandler($this->feedbackRepository, $this->eventRepository, $purifier, $config);
    }

    public function test_refuses_an_event_outside_the_allowlist(): void
    {
        $this->eventRepository->shouldNotReceive('findFirstWhere');
        $this->feedbackRepository->shouldNotReceive('create');

        $this->expectException(EventFeedbackNotOpenException::class);

        $this->handler->handle(new SubmitEventFeedbackDTO(event_id: 7, rating: 5));
    }

    public function test_refuses_a_rating_out_of_bounds(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $this->feedbackRepository->shouldNotReceive('create');

        $this->expectException(EventFeedbackNotOpenException::class);

        $this->handler->handle(new SubmitEventFeedbackDTO(event_id: 14, rating: 6));
    }

    public function test_purifies_free_text_and_stores_a_new_answer(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $this->feedbackRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->feedbackRepository->shouldReceive('create')->once()->withArgs(function (array $attributes) {
            return $attributes['event_id'] === 14
                && $attributes['contact_code'] === 'abc123'
                && $attributes['rating'] === 4
                && $attributes['title'] === 'Super soirée'
                && $attributes['message'] === 'Bravo à tous'
                && $attributes['first_name'] === null
                && $attributes['publish_consent'] === true
                && strlen((string) $attributes['ip_hash']) === 64;
        })->andReturn(new EventFeedbackDomainObject);

        $this->handler->handle(new SubmitEventFeedbackDTO(
            event_id: 14,
            rating: 4,
            contact_code: 'abc123',
            title: '<b>Super</b> soirée<script>alert(1)</script>',
            first_name: '   ',
            message: "Bravo\n à <i>tous</i>",
            publish_consent: true,
            ip: '1.2.3.4',
        ));
    }

    public function test_a_second_answer_with_the_same_code_replaces_the_first(): void
    {
        $existing = (new EventFeedbackDomainObject)->setId(9);
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $this->feedbackRepository->shouldReceive('findFirstWhere')
            ->with(['event_id' => 14, 'contact_code' => 'abc123'])
            ->andReturn($existing);
        $this->feedbackRepository->shouldNotReceive('create');
        $this->feedbackRepository->shouldReceive('updateFromArray')->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 9 && $attributes['rating'] === 2)
            ->andReturn($existing);

        $this->handler->handle(new SubmitEventFeedbackDTO(event_id: 14, rating: 2, contact_code: 'abc123'));
    }

    public function test_an_answer_without_code_is_always_added(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $this->feedbackRepository->shouldNotReceive('findFirstWhere');
        $this->feedbackRepository->shouldReceive('create')->once()
            ->withArgs(fn (array $attributes) => $attributes['contact_code'] === null && $attributes['rating'] === 5)
            ->andReturn(new EventFeedbackDomainObject);

        $this->handler->handle(new SubmitEventFeedbackDTO(event_id: 20, rating: 5));
    }
}
