<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Enums\SmsAutoReplySkipReason;
use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\SendSmsAutoReplyHandler;
use HiEvents\Services\Application\Handlers\Sms\SendSmsReplyHandler;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAutoReplyCheckDTO;
use HiEvents\Services\Domain\Sms\SmsAutoReplyService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SendSmsAutoReplyHandlerTest extends TestCase
{
    private const ANSWER = 'Ça commence à 20h.';

    private const HOLDING = "Je vérifie avec l'équipe et je reviens vers vous très vite\n\nKultur'm via PICHA Ticket";

    private SmsAutoReplyService|MockInterface $autoReplyService;

    private SmsConversationRepositoryInterface|MockInterface $conversations;

    private SmsSendService|MockInterface $sendService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autoReplyService = Mockery::mock(SmsAutoReplyService::class);
        $this->conversations = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->sendService = Mockery::mock(SmsSendService::class);
    }

    public function test_nothing_is_sent_when_a_guardrail_blocks(): void
    {
        $this->autoReplyService->shouldReceive('check')->once()->with(3, 9, self::ANSWER, self::HOLDING)
            ->andReturn(new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::ACKNOWLEDGEMENT, $this->conversation('OPEN')));
        $this->autoReplyService->shouldNotReceive('keepWaitingForTeam');
        $this->sendService->shouldNotReceive('send');

        self::assertNull($this->handler()->handle(3, 9, self::ANSWER, self::HOLDING));
    }

    public function test_answer_is_sent_by_the_assistant_through_the_reply_path_and_marked_automatic(): void
    {
        $conversation = $this->conversation('OPEN');
        $this->autoReplyService->shouldReceive('check')->andReturn(new SmsAutoReplyCheckDTO(null, $conversation, SmsAutoReplyType::ANSWER, self::ANSWER));
        $this->conversations->shouldReceive('findFirstWhere')->with(['id' => 3, 'organizer_id' => 8])->andReturn($conversation);
        $this->conversations->shouldNotReceive('updateWhere');
        $this->sendService->shouldReceive('send')->once()
            ->withArgs(fn (SendSmsDTO $sms) => $sms->content === self::ANSWER
                && $sms->authorType === SmsMessageAuthorType::AGENT
                && $sms->sentByUserId === null
                && $sms->autoReplyToMessageId === 9
                && $sms->autoReplyType === SmsAutoReplyType::ANSWER
                && $sms->keepDraft === false)
            ->andReturn((new SmsMessageDomainObject)->setId(50));

        self::assertSame(50, $this->handler()->handle(3, 9, self::ANSWER, self::HOLDING)?->getId());
    }

    public function test_holding_reply_keeps_the_conversation_waiting_and_the_draft_for_the_team(): void
    {
        $conversation = $this->conversation('NEEDS_HUMAN');
        $this->autoReplyService->shouldReceive('check')->andReturn(new SmsAutoReplyCheckDTO(null, $conversation, SmsAutoReplyType::HOLDING, self::HOLDING));
        $this->autoReplyService->shouldReceive('keepWaitingForTeam')->once()->with($conversation);
        $this->conversations->shouldReceive('findFirstWhere')->andReturn($conversation);
        $this->conversations->shouldNotReceive('updateWhere');
        $this->sendService->shouldReceive('send')->once()
            ->withArgs(fn (SendSmsDTO $sms) => $sms->content === self::HOLDING
                && $sms->autoReplyType === SmsAutoReplyType::HOLDING
                && $sms->autoReplyToMessageId === 9
                && $sms->keepDraft === true)
            ->andReturn((new SmsMessageDomainObject)->setId(51));

        self::assertSame(51, $this->handler()->handle(3, 9, null, self::HOLDING)?->getId());
    }

    public function test_second_automatic_reply_to_the_same_sms_is_refused_by_the_database(): void
    {
        $conversation = $this->conversation('OPEN');
        $this->autoReplyService->shouldReceive('check')->andReturn(new SmsAutoReplyCheckDTO(null, $conversation, SmsAutoReplyType::ANSWER, self::ANSWER));
        $this->conversations->shouldReceive('findFirstWhere')->andReturn($conversation);
        $this->sendService->shouldReceive('send')->once()->andThrow(Mockery::mock(UniqueConstraintViolationException::class));

        self::assertNull($this->handler()->handle(3, 9, self::ANSWER, null));
    }

    public function test_gateway_failure_is_logged_without_retry(): void
    {
        $conversation = $this->conversation('OPEN');
        $this->autoReplyService->shouldReceive('check')->andReturn(new SmsAutoReplyCheckDTO(null, $conversation, SmsAutoReplyType::ANSWER, self::ANSWER));
        $this->conversations->shouldReceive('findFirstWhere')->andReturn($conversation);
        $this->sendService->shouldReceive('send')->once()->andThrow(new SmsGatewayException('offline'));

        self::assertNull($this->handler()->handle(3, 9, self::ANSWER, null));
    }

    private function handler(): SendSmsAutoReplyHandler
    {
        return new SendSmsAutoReplyHandler(
            $this->autoReplyService,
            new SendSmsReplyHandler($this->conversations, $this->sendService),
            new NullLogger,
        );
    }

    private function conversation(string $status): SmsConversationDomainObject
    {
        return (new SmsConversationDomainObject)
            ->setId(3)
            ->setOrganizerId(8)
            ->setPhone('+262639000001')
            ->setStatus($status)
            ->setDraftReply(self::ANSWER)
            ->setDraftSource('AGENT');
    }
}
