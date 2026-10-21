<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsAssistantOutcome;
use HiEvents\DomainObjects\Enums\SmsAssistantTrigger;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Jobs\Sms\GenerateSmsDraftJob;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantRequestDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantResultDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantEventCatalogService;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsAssistantServiceTest extends TestCase
{
    private SmsAssistantReplyGenerator|MockInterface $generator;

    private SmsAssistantEventCatalogService|MockInterface $catalogService;

    private SmsConversationService|MockInterface $conversationService;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private OrganizerRepositoryInterface|MockInterface $organizerRepository;

    private SmsAssistantService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = Mockery::mock(SmsAssistantReplyGenerator::class);
        $this->catalogService = Mockery::mock(SmsAssistantEventCatalogService::class);
        $this->conversationService = Mockery::mock(SmsConversationService::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);

        $this->service = new SmsAssistantService(
            $this->generator,
            $this->catalogService,
            $this->conversationService,
            $this->settingsRepository,
            $this->conversationRepository,
            $this->messageRepository,
            $this->organizerRepository,
        );
    }

    public function test_customer_question_is_queued_for_a_draft(): void
    {
        Bus::fake();
        $this->generator->shouldReceive('isConfigured')->andReturnTrue();

        $this->service->queueDraftIfEligible($this->settings(), $this->inbound(9, 'Bonjour, ça commence à quelle heure ?'));

        Bus::assertDispatched(GenerateSmsDraftJob::class, fn (GenerateSmsDraftJob $job) => $job->conversationId === 3 && $job->messageId === 9);
    }

    public function test_stop_start_and_reactions_are_ignored(): void
    {
        Bus::fake();
        $this->generator->shouldReceive('isConfigured')->andReturnTrue();

        foreach (['STOP', 'Stop SMS', 'START', 'A aimé « Bonjour »', 'Liked "See you"', '👍', '❤️❤️'] as $index => $content) {
            $this->service->queueDraftIfEligible($this->settings(), $this->inbound($index + 1, $content));
        }

        Bus::assertNotDispatched(GenerateSmsDraftJob::class);
    }

    public function test_nothing_is_queued_when_the_assistant_is_off_or_the_key_is_missing(): void
    {
        Bus::fake();
        $message = $this->inbound(9, 'Il reste des places ?');

        $this->generator->shouldReceive('isConfigured')->andReturnTrue();
        $this->service->queueDraftIfEligible($this->settings(enabled: false), $message);

        $this->generator = Mockery::mock(SmsAssistantReplyGenerator::class);
        $this->generator->shouldReceive('isConfigured')->andReturnFalse();
        $this->setUpServiceWithCurrentMocks();
        $this->service->queueDraftIfEligible($this->settings(), $message);

        Bus::assertNotDispatched(GenerateSmsDraftJob::class);
    }

    public function test_draft_is_saved_on_the_conversation(): void
    {
        $this->expectDraftContext();
        $this->generator->shouldReceive('generate')->once()
            ->withArgs(fn (SmsAssistantRequestDTO $request) => $request->trigger === SmsAssistantTrigger::INBOUND
                && $request->conversationId === 3
                && $request->instructions === 'Tutoie.'
                && $request->thread->count() === 2)
            ->andReturn(new SmsAssistantResultDTO(SmsAssistantOutcome::DRAFT, 'Salut ! Ça commence à 22h.', null));

        $this->conversationRepository->shouldReceive('updateFromArray')->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 3
                && $attributes['draft_reply'] === 'Salut ! Ça commence à 22h.'
                && $attributes['draft_source'] === 'AGENT'
                && $attributes['draft_generated_at'] !== null
                && ! array_key_exists('status', $attributes));

        $result = $this->service->draftForInboundMessage(3, 9);

        self::assertSame(SmsAssistantOutcome::DRAFT, $result->outcome);
    }

    public function test_sensitive_message_marks_the_conversation_for_a_human(): void
    {
        $this->expectDraftContext();
        $this->generator->shouldReceive('generate')->once()
            ->andReturn(new SmsAssistantResultDTO(SmsAssistantOutcome::NEEDS_HUMAN, null, 'Demande de remboursement'));

        $this->conversationRepository->shouldReceive('updateFromArray')->once()
            ->withArgs(fn (int $id, array $attributes) => $attributes['status'] === 'NEEDS_HUMAN'
                && $attributes['needs_human_reason'] === 'Demande de remboursement'
                && $attributes['draft_reply'] === null);

        $this->service->draftForInboundMessage(3, 9);
    }

    public function test_outdated_message_is_skipped(): void
    {
        $this->generator->shouldReceive('isConfigured')->andReturnTrue();
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation());
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());
        $this->messageRepository->shouldReceive('findLatestForConversation')->andReturn(collect([
            $this->inbound(9, 'Il reste des places ?'),
            $this->inbound(10, 'Et pour 4 personnes ?'),
        ]));
        $this->generator->shouldNotReceive('generate');

        self::assertNull($this->service->draftForInboundMessage(3, 9));
    }

    public function test_conversation_already_handled_by_the_team_is_skipped(): void
    {
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation()->setStatus('NEEDS_HUMAN'));
        $this->generator->shouldNotReceive('generate');

        self::assertNull($this->service->draftForInboundMessage(3, 9));
    }

    public function test_regenerate_requires_the_assistant_to_be_on(): void
    {
        $this->generator->shouldReceive('isConfigured')->andReturnTrue();
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(enabled: false));
        $this->generator->shouldNotReceive('generate');

        $this->expectException(SmsAssistantUnavailableException::class);
        $this->service->regenerate($this->conversation());
    }

    private function expectDraftContext(): void
    {
        $this->generator->shouldReceive('isConfigured')->andReturnTrue();
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation());
        $this->conversationRepository->shouldReceive('findOrdersByPhone')->andReturn(collect());
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());
        $this->conversationService->shouldReceive('isOptedOut')->andReturnFalse();
        $this->catalogService->shouldReceive('getUpcomingEvents')->with(4)->andReturn(collect());
        $this->organizerRepository->shouldReceive('findById')->with(4)->andReturn(
            (new OrganizerDomainObject)->setId(4)->setName('Innocent Event')->setTimezone('Indian/Mayotte'),
        );
        $this->messageRepository->shouldReceive('findLatestForConversation')->with(3, 20)->andReturn(collect([
            (new SmsMessageDomainObject)->setId(8)->setSmsConversationId(3)->setDirection('OUTBOUND')->setAuthorType('CAMPAIGN')->setContent('Halloween arrive !'),
            $this->inbound(9, 'Il reste des places ?'),
        ]));
    }

    private function setUpServiceWithCurrentMocks(): void
    {
        $this->service = new SmsAssistantService(
            $this->generator,
            $this->catalogService,
            $this->conversationService,
            $this->settingsRepository,
            $this->conversationRepository,
            $this->messageRepository,
            $this->organizerRepository,
        );
    }

    private function settings(bool $enabled = true): OrganizerSmsSettingDomainObject
    {
        return (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setOrganizerId(4)
            ->setAssistantEnabled($enabled)
            ->setAssistantInstructions('Tutoie.');
    }

    private function conversation(): SmsConversationDomainObject
    {
        return (new SmsConversationDomainObject)
            ->setId(3)
            ->setOrganizerId(4)
            ->setPhone('+262639123456')
            ->setStatus('OPEN');
    }

    private function inbound(int $id, string $content): SmsMessageDomainObject
    {
        return (new SmsMessageDomainObject)
            ->setId($id)
            ->setSmsConversationId(3)
            ->setDirection('INBOUND')
            ->setAuthorType('CONTACT')
            ->setContent($content);
    }
}
