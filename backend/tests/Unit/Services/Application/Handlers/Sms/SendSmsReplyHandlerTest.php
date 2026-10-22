<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\SendSmsReplyHandler;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Mockery;
use Tests\TestCase;

class SendSmsReplyHandlerTest extends TestCase
{
    private const DRAFT = "Salut ! La soirée commence à 22h.\nÀ très vite !";

    public function test_unchanged_suggestion_is_sent_as_the_assistant(): void
    {
        self::assertSame(SmsMessageAuthorType::AGENT, $this->sentAuthor(' '.self::DRAFT."\n"));
    }

    public function test_edited_suggestion_is_sent_as_a_human(): void
    {
        self::assertSame(SmsMessageAuthorType::HUMAN, $this->sentAuthor(str_replace('22h', '23h', self::DRAFT)));
    }

    public function test_organizer_cannot_reply_in_the_conversation_of_another_organizer_of_the_line(): void
    {
        $conversations = Mockery::mock(SmsConversationRepositoryInterface::class);
        $conversations->shouldReceive('findFirstWhere')->with(['id' => 3, 'organizer_id' => 8])->andReturnNull();
        $sendService = Mockery::mock(SmsSendService::class);
        $sendService->shouldNotReceive('send');

        $this->expectException(ResourceNotFoundException::class);
        (new SendSmsReplyHandler($conversations, $sendService))->handle(8, 3, 'Bonjour', 1);
    }

    public function test_team_reply_reopens_a_conversation_waiting_for_the_team(): void
    {
        $conversations = Mockery::mock(SmsConversationRepositoryInterface::class);
        $conversations->shouldReceive('findFirstWhere')->andReturn((new SmsConversationDomainObject)
            ->setId(413)->setOrganizerId(8)->setPhone('+262639000001')->setStatus('NEEDS_HUMAN'));
        $conversations->shouldReceive('updateWhere')->once()
            ->with(['status' => 'OPEN', 'needs_human_reason' => null], ['id' => 413]);
        $sendService = Mockery::mock(SmsSendService::class);
        $sendService->shouldReceive('send')->once()->andReturn(new SmsMessageDomainObject);

        (new SendSmsReplyHandler($conversations, $sendService))->handle(8, 413, 'Les artistes seront annoncés bientôt', 1);
    }

    public function test_reply_in_an_open_conversation_does_not_change_its_status(): void
    {
        $conversations = Mockery::mock(SmsConversationRepositoryInterface::class);
        $conversations->shouldReceive('findFirstWhere')->andReturn((new SmsConversationDomainObject)
            ->setId(5)->setOrganizerId(8)->setPhone('+262639000001')->setStatus('OPEN'));
        $conversations->shouldNotReceive('updateWhere');
        $sendService = Mockery::mock(SmsSendService::class);
        $sendService->shouldReceive('send')->once()->andReturn(new SmsMessageDomainObject);

        (new SendSmsReplyHandler($conversations, $sendService))->handle(8, 5, 'Bonjour', 1);
    }

    private function sentAuthor(string $content): SmsMessageAuthorType
    {
        $conversations = Mockery::mock(SmsConversationRepositoryInterface::class);
        $conversations->shouldReceive('findFirstWhere')->andReturn((new SmsConversationDomainObject)
            ->setId(3)
            ->setOrganizerId(4)
            ->setPhone('+262639123456')
            ->setDraftReply(self::DRAFT)
            ->setDraftSource('AGENT'));

        $author = null;
        $sendService = Mockery::mock(SmsSendService::class);
        $sendService->shouldReceive('send')->once()->andReturnUsing(function (SendSmsDTO $sms) use (&$author) {
            $author = $sms->authorType;

            return new SmsMessageDomainObject;
        });

        (new SendSmsReplyHandler($conversations, $sendService))->handle(4, 3, $content, 1);

        return $author;
    }
}
