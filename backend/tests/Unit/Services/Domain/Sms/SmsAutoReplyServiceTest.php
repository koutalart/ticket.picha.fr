<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsAssistantOutcome;
use HiEvents\DomainObjects\Enums\SmsAutoReplySkipReason;
use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Jobs\Sms\SendSmsAutoReplyJob;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantResultDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsAutoReplyService;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Domain\Sms\SmsLineService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Unit\Support\StaticCustomerLocaleResolver;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;

class SmsAutoReplyServiceTest extends TestCase
{
    private StaticCustomerLocaleResolver $customerLocaleResolver;

    private const REPLY = 'Ça commence à 20h, ouverture des portes à 19h.';

    private const QUESTION = 'Bonsoir, ça commence à quelle heure ?';

    private const HOLDING = "Bonne question, je vérifie avec l'équipe et je reviens vers vous très vite 🎶\n\nKultur'm via PICHA Ticket";

    private SmsAssistantService|MockInterface $assistantService;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private SmsConversationService|MockInterface $conversationService;

    private SmsLineService|MockInterface $lineService;

    private OrganizerSmsSettingDomainObject $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = (new OrganizerSmsSettingDomainObject)->setId(20)->setOrganizerId(8)->setAssistantEnabled(true)->setAssistantAutoSend(true);

        $this->assistantService = Mockery::mock(SmsAssistantService::class);
        $this->assistantService->shouldReceive('isActiveFor')->andReturnUsing(
            fn (?OrganizerSmsSettingDomainObject $settings) => $settings !== null && $settings->getAssistantEnabled(),
        )->byDefault();

        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['organizer_id' => 8])->andReturnUsing(fn () => $this->settings)->byDefault();

        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->conversationRepository->shouldReceive('findFirstWhere')->with(['id' => 3])->andReturn($this->conversation())->byDefault();

        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->mockThread(self::QUESTION);

        $this->conversationService = Mockery::mock(SmsConversationService::class);
        $this->conversationService->shouldReceive('isOptedOut')->andReturnFalse()->byDefault();

        $this->lineService = Mockery::mock(SmsLineService::class);
        $this->lineService->shouldReceive('relatedOrganizerIds')->andReturnUsing(fn () => [$this->settings->getOrganizerId()])->byDefault();
    }

    public function test_simple_answer_within_the_instructions_can_leave_on_its_own(): void
    {
        $this->messageRepository->shouldReceive('hasOutboundToPhoneAfter')->once()
            ->withArgs(fn (array $organizerIds, string $phone, int $afterId, string $since) => $organizerIds === [8]
                && $phone === '+262639000001' && $afterId === 9 && $since !== '')
            ->andReturnFalse();

        $check = $this->service()->check(3, 9, self::REPLY."\n", self::HOLDING);

        self::assertTrue($check->isAllowed());
        self::assertSame(SmsAutoReplyType::ANSWER, $check->type);
        self::assertSame(self::REPLY."\n", $check->content);
        self::assertSame(3, $check->conversation->getId());
    }

    public function test_setting_is_off_by_default(): void
    {
        $this->settings = (new OrganizerSmsSettingDomainObject)->setId(20)->setOrganizerId(8)->setAssistantEnabled(true);

        self::assertFalse($this->settings->getAssistantAutoSend());
        $this->assertSkipped(SmsAutoReplySkipReason::DISABLED, self::REPLY, self::HOLDING);
    }

    public function test_nothing_leaves_when_the_assistant_is_off(): void
    {
        $this->settings->setAssistantEnabled(false);

        $this->assertSkipped(SmsAutoReplySkipReason::DISABLED, self::REPLY, self::HOLDING);
    }

    public function test_empty_texts_are_never_sent(): void
    {
        $this->assertSkipped(SmsAutoReplySkipReason::EMPTY_REPLY, "  \n ", ' ');
        $this->assertSkipped(SmsAutoReplySkipReason::EMPTY_REPLY, null, null);
    }

    public function test_conversation_waiting_for_the_team_gets_only_the_holding_reply(): void
    {
        $this->conversationRepository->shouldReceive('findFirstWhere')->with(['id' => 3])
            ->andReturn($this->conversation()->setStatus('NEEDS_HUMAN'));

        $this->assertHolding();
        $this->assertSkipped(SmsAutoReplySkipReason::EMPTY_REPLY, self::REPLY, null);
    }

    public function test_assistant_needing_a_human_still_answers_with_the_holding_reply(): void
    {
        $this->mockThread('Oui');
        $this->conversationRepository->shouldReceive('findFirstWhere')->with(['id' => 3])
            ->andReturn($this->conversation()->setStatus('NEEDS_HUMAN')->setDraftReply(null)->setDraftSource(null));

        $this->assertHolding(null);
    }

    public function test_draft_replaced_since_is_not_sent_at_all(): void
    {
        $this->conversationRepository->shouldReceive('findFirstWhere')->with(['id' => 3])
            ->andReturn($this->conversation()->setDraftReply('Une autre réponse'));
        $this->assertSkipped(SmsAutoReplySkipReason::DRAFT_CHANGED, self::REPLY, self::HOLDING);

        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->conversationRepository->shouldReceive('findFirstWhere')->with(['id' => 3])
            ->andReturn($this->conversation()->setDraftReply(null)->setDraftSource(null));
        $this->assertSkipped(SmsAutoReplySkipReason::DRAFT_CHANGED);
    }

    public function test_customer_message_that_needs_no_reply_is_not_answered(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->with(['id' => 9, 'sms_conversation_id' => 3])->andReturn($this->inbound(9, 'STOP'));

        $this->assertSkipped(SmsAutoReplySkipReason::NOT_REPLYABLE, self::REPLY, self::HOLDING);
    }

    public function test_older_customer_message_is_not_answered(): void
    {
        $this->messageRepository->shouldReceive('findLatestForConversation')->with(3, 20)
            ->andReturn(collect([$this->inbound(9, self::QUESTION), $this->inbound(10, 'Et le parking ?')]));

        $this->assertSkipped(SmsAutoReplySkipReason::NOT_LATEST, self::REPLY, self::HOLDING);
    }

    public function test_pure_acknowledgements_get_no_reply_at_all(): void
    {
        foreach (['ok', 'Merci !', "D'accord", '👍 merci', 'Super', 'OK MERCI BEAUCOUP'] as $content) {
            $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
            $this->mockThread($content);

            $this->assertSkipped(SmsAutoReplySkipReason::ACKNOWLEDGEMENT, self::REPLY, self::HOLDING, $content);
        }
    }

    public function test_sensitive_cases_keep_the_answer_as_a_draft_and_send_only_the_holding_reply(): void
    {
        foreach ([
            'Je veux faire une réclamation',
            'Je peux inviter qqn pour le concert ?',
            'Vous avez des invitations pour nous ?',
            'Je suis journaliste, je peux venir couvrir la soirée ?',
            'Les mineurs peuvent entrer ?',
            'Mon enfant de 10 ans peut venir ?',
            'Y a-t-il un accès PMR ?',
            'Je suis en situation de handicap',
            'Je veux un remboursement',
            'Service de presse ici',
        ] as $content) {
            $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
            $this->mockThread($content);

            $this->assertHolding(self::REPLY, $content);
            $this->assertSkipped(SmsAutoReplySkipReason::EMPTY_REPLY, self::REPLY, null, $content);
        }
    }

    public function test_sensitive_word_in_the_draft_or_earlier_in_the_thread_keeps_the_answer_as_a_draft(): void
    {
        $this->conversationRepository->shouldReceive('findFirstWhere')->with(['id' => 3])
            ->andReturn($this->conversation()->setDraftReply('Pour le remboursement, écrivez-nous.'));
        $this->assertHolding('Pour le remboursement, écrivez-nous.');

        $this->setUp();
        $this->messageRepository->shouldReceive('findLatestInbound')
            ->andReturn(collect([$this->inbound(7, 'Je suis en fauteuil roulant'), $this->inbound(9, self::QUESTION)]));
        $this->assertHolding();
    }

    public function test_opted_out_number_gets_nothing(): void
    {
        $this->conversationService->shouldReceive('isOptedOut')->with(8, '+262639000001')->andReturnTrue();

        $this->assertSkipped(SmsAutoReplySkipReason::OPTED_OUT, self::REPLY, self::HOLDING);
    }

    public function test_only_one_automatic_reply_per_customer_message(): void
    {
        $this->messageRepository->shouldReceive('countWhere')->with(['auto_reply_to_message_id' => 9])->andReturn(1);

        $this->assertSkipped(SmsAutoReplySkipReason::ALREADY_ANSWERED, self::REPLY, self::HOLDING);
        $this->assertSkipped(SmsAutoReplySkipReason::ALREADY_ANSWERED, null, self::HOLDING);
    }

    public function test_no_automatic_reply_once_someone_wrote_to_the_contact_or_an_sms_is_waiting(): void
    {
        $this->messageRepository->shouldReceive('hasOutboundToPhoneAfter')->andReturnTrue();

        $this->assertSkipped(SmsAutoReplySkipReason::OUTBOUND_AFTER_MESSAGE, self::REPLY, self::HOLDING);
        $this->assertSkipped(SmsAutoReplySkipReason::OUTBOUND_AFTER_MESSAGE, null, self::HOLDING);
    }

    public function test_no_second_holding_reply_in_a_row(): void
    {
        $holding = (new SmsMessageDomainObject)->setId(8)->setDirection('OUTBOUND')->setAutoReplyType('HOLDING')
            ->setCreatedAt(now('UTC')->subMinutes(10)->toDateTimeString());
        $this->messageRepository->shouldReceive('findLatestForConversation')->with(3, 20)
            ->andReturn(collect([$holding, $this->inbound(9, self::QUESTION)]));

        $this->assertSkipped(SmsAutoReplySkipReason::HOLDING_ALREADY_SENT, null, self::HOLDING);
    }

    public function test_shared_line_checks_the_sms_of_every_organizer_of_the_line(): void
    {
        $this->settings->setSharedLineSettingsId(30);
        $this->lineService->shouldReceive('relatedOrganizerIds')->with($this->settings, Mockery::any())->andReturn([4, 8]);
        $this->messageRepository->shouldReceive('hasOutboundToPhoneAfter')->once()
            ->withArgs(fn (array $organizerIds) => $organizerIds === [4, 8])
            ->andReturnTrue();

        $this->assertSkipped(SmsAutoReplySkipReason::OUTBOUND_AFTER_MESSAGE, self::REPLY, self::HOLDING);
    }

    public function test_unavailable_shared_line_sends_nothing(): void
    {
        $this->lineService->shouldReceive('relatedOrganizerIds')->andThrow(new SmsGatewayNotConfiguredException('no line'));

        $this->assertSkipped(SmsAutoReplySkipReason::LINE_UNAVAILABLE, self::REPLY, self::HOLDING);
    }

    public function test_holding_reply_keeps_the_conversation_waiting_for_the_team(): void
    {
        $this->conversationRepository->shouldReceive('updateWhere')->once()
            ->withArgs(fn (array $attributes, array $where) => $attributes['status'] === 'NEEDS_HUMAN'
                && $attributes['needs_human_reason'] !== null
                && $where === ['id' => 3]);

        $this->service()->keepWaitingForTeam($this->conversation());
        $this->service()->keepWaitingForTeam($this->conversation()->setStatus('NEEDS_HUMAN'));
    }

    public function test_default_holding_reply_says_tu_when_the_organizer_chose_it(): void
    {
        $service = $this->service();
        $this->customerLocaleResolver->addressForm = CustomerAddressForm::TU;
        $this->settings->setAssistantHoldingReply(null);

        self::assertSame(
            "Bonne question, je vérifie avec l'équipe et je reviens vers toi très vite.",
            $service->holdingReplyFor($this->settings, null),
        );
    }

    public function test_holding_reply_comes_from_the_assistant_then_the_organizer_then_a_default(): void
    {
        $service = $this->service();
        $fromAssistant = new SmsAssistantResultDTO(SmsAssistantOutcome::NEEDS_HUMAN, null, 'Invitation', holdingReply: self::HOLDING);

        self::assertSame(self::HOLDING, $service->holdingReplyFor($this->settings, $fromAssistant));

        $this->settings->setAssistantHoldingReply("Je regarde ça et je reviens vers vous.\n\nL'équipe");
        self::assertSame("Je regarde ça et je reviens vers vous.\n\nL'équipe", $service->holdingReplyFor($this->settings, null));

        $this->settings->setAssistantHoldingReply(null);
        $default = $service->holdingReplyFor($this->settings, null);
        self::assertNotSame('', $default);
        self::assertStringNotContainsString("Kultur'm", $default);
        foreach (['•', '·', '—', '–'] as $character) {
            self::assertStringNotContainsString($character, $default);
        }
    }

    public function test_safe_draft_is_queued_a_few_seconds_later_with_its_holding_reply(): void
    {
        Bus::fake();

        $queued = $this->service()->queueIfEligible(3, 9, new SmsAssistantResultDTO(SmsAssistantOutcome::DRAFT, self::REPLY, null, autoSendSafe: true, holdingReply: self::HOLDING));

        self::assertTrue($queued);
        Bus::assertDispatched(SendSmsAutoReplyJob::class, fn (SendSmsAutoReplyJob $job) => $job->conversationId === 3
            && $job->messageId === 9
            && $job->answer === self::REPLY
            && $job->holdingReply === self::HOLDING
            && $job->delay !== null);
    }

    public function test_doubt_or_needs_human_queues_only_the_holding_reply(): void
    {
        Bus::fake();
        $service = $this->service();

        self::assertTrue($service->queueIfEligible(3, 9, new SmsAssistantResultDTO(SmsAssistantOutcome::DRAFT, self::REPLY, null, holdingReply: self::HOLDING)));
        self::assertTrue($service->queueIfEligible(3, 9, new SmsAssistantResultDTO(SmsAssistantOutcome::NEEDS_HUMAN, null, 'Invitation', autoSendSafe: true, holdingReply: self::HOLDING)));

        Bus::assertDispatchedTimes(SendSmsAutoReplyJob::class, 2);
        Bus::assertNotDispatched(SendSmsAutoReplyJob::class, fn (SendSmsAutoReplyJob $job) => $job->answer !== null);
    }

    public function test_nothing_is_queued_when_the_setting_is_off_or_the_assistant_failed(): void
    {
        Bus::fake();
        $service = $this->service();

        self::assertFalse($service->queueIfEligible(3, 9, null));

        $this->settings->setAssistantAutoSend(false);
        self::assertFalse($service->queueIfEligible(3, 9, new SmsAssistantResultDTO(SmsAssistantOutcome::DRAFT, self::REPLY, null, autoSendSafe: true)));

        Bus::assertNotDispatched(SendSmsAutoReplyJob::class);
    }

    private function assertHolding(?string $answer = self::REPLY, string $message = ''): void
    {
        $check = $this->service()->check(3, 9, $answer, self::HOLDING);

        self::assertTrue($check->isAllowed(), $message.' '.$check->skipReason?->name);
        self::assertSame(SmsAutoReplyType::HOLDING, $check->type, $message);
        self::assertSame(self::HOLDING, $check->content, $message);
    }

    private function assertSkipped(SmsAutoReplySkipReason $reason, ?string $answer = self::REPLY, ?string $holding = null, string $message = ''): void
    {
        $check = $this->service()->check(3, 9, $answer, $holding);

        self::assertFalse($check->isAllowed(), $message);
        self::assertSame($reason, $check->skipReason, $message);
    }

    private function mockThread(string $content): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->with(['id' => 9, 'sms_conversation_id' => 3])->andReturn($this->inbound(9, $content))->byDefault();
        $this->messageRepository->shouldReceive('findLatestForConversation')->with(3, 20)->andReturn(collect([$this->inbound(9, $content)]))->byDefault();
        $this->messageRepository->shouldReceive('findLatestInbound')->andReturn(collect([$this->inbound(9, $content)]))->byDefault();
        $this->messageRepository->shouldReceive('countWhere')->with(['auto_reply_to_message_id' => 9])->andReturn(0)->byDefault();
        $this->messageRepository->shouldReceive('hasOutboundToPhoneAfter')->andReturnFalse()->byDefault();
    }

    private function service(): SmsAutoReplyService
    {
        return new SmsAutoReplyService(
            $this->assistantService,
            $this->settingsRepository,
            $this->conversationRepository,
            $this->messageRepository,
            $this->conversationService,
            $this->lineService,
            $this->customerLocaleResolver = new StaticCustomerLocaleResolver,
        );
    }

    private function conversation(): SmsConversationDomainObject
    {
        return (new SmsConversationDomainObject)
            ->setId(3)
            ->setOrganizerId(8)
            ->setPhone('+262639000001')
            ->setStatus('OPEN')
            ->setDraftReply(self::REPLY)
            ->setDraftSource('AGENT');
    }

    private function inbound(int $id, string $content): SmsMessageDomainObject
    {
        return (new SmsMessageDomainObject)
            ->setId($id)
            ->setSmsConversationId(3)
            ->setOrganizerId(8)
            ->setDirection('INBOUND')
            ->setAuthorType('CONTACT')
            ->setContent($content)
            ->setStatus('RECEIVED');
    }
}
