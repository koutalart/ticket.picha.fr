<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsAssistantOutcome;
use HiEvents\DomainObjects\Enums\SmsAssistantTrigger;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\SmsAssistantCallDomainObject;
use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Repository\Interfaces\SmsAssistantCallRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantRequestDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantThreadMessageDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantTicketInfoDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use Illuminate\Config\Repository;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsAssistantReplyGeneratorTest extends TestCase
{
    private ClaudeStructuredOutputClient|MockInterface $claude;

    private SmsAssistantCallRepositoryInterface|MockInterface $callRepository;

    private array $loggedCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $this->callRepository = Mockery::mock(SmsAssistantCallRepositoryInterface::class);
        $this->callRepository->shouldReceive('countWhere')->andReturn(0)->byDefault();
        $this->callRepository->shouldReceive('create')->andReturnUsing(function (array $attributes) {
            $this->loggedCalls[] = $attributes;

            return new SmsAssistantCallDomainObject;
        })->byDefault();
    }

    public function test_draft_is_cleaned_unsigned_and_logged_without_its_content(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturnUsing(function (string $system, array $content) use (&$sent, &$systemPrompt) {
            $sent = $content[0]['text'];
            $systemPrompt = $system;

            return $this->answer([
                'decision' => 'draft',
                'reply' => "Bonjour Anaïs ! La soirée commence à 22h — l'entrée flash est à 15 €.\n• Lien : https://ticket.picha.fr/event/7/halloween",
                'reason' => null,
            ]);
        });

        $result = $this->generator()->generate($this->request());

        self::assertSame(SmsAssistantOutcome::DRAFT, $result->outcome);
        self::assertNull($result->reason);
        self::assertStringNotContainsString('—', $result->reply);
        self::assertStringNotContainsString('•', $result->reply);
        self::assertStringContainsString("22h, l'entrée flash est à 15 €", $result->reply);
        self::assertStringEndsWith('https://ticket.picha.fr/event/7/halloween', $result->reply);
        self::assertStringNotContainsString('assistant', $result->reply);

        self::assertStringContainsString('160 caractères', $systemPrompt);
        self::assertStringContainsString('sans les frais', $systemPrompt);
        self::assertStringContainsString('Ne sois pas répétitif', $systemPrompt);
        self::assertStringContainsString('Lien personnel du client : aucun', $sent);
        self::assertStringContainsString('Tutoie les clients.', $sent);
        self::assertStringContainsString('Halloween', $sent);
        self::assertStringContainsString('"prix": 15', $sent);
        self::assertStringContainsString('Client : Il reste des places ?', $sent);
        self::assertStringContainsString('"prenom": "Anaïs"', $sent);
        self::assertStringNotContainsString('anais@example.com', $sent);

        self::assertCount(1, $this->loggedCalls);
        self::assertSame('DRAFT', $this->loggedCalls[0]['outcome']);
        self::assertSame('INBOUND', $this->loggedCalls[0]['trigger']);
        self::assertSame(1200, $this->loggedCalls[0]['input_tokens']);
        self::assertArrayNotHasKey('content', $this->loggedCalls[0]);
    }

    public function test_sensitive_request_is_handed_to_a_human(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer([
            'decision' => 'needs_human',
            'reply' => 'Je vous rembourse tout de suite',
            'reason' => 'Demande de remboursement — billet acheté en double',
        ]));

        $result = $this->generator()->generate($this->request());

        self::assertSame(SmsAssistantOutcome::NEEDS_HUMAN, $result->outcome);
        self::assertNull($result->reply);
        self::assertSame('Demande de remboursement, billet acheté en double', $result->reason);
        self::assertSame('NEEDS_HUMAN', $this->loggedCalls[0]['outcome']);
    }

    public function test_missing_api_key_makes_the_assistant_unavailable_without_calling_claude(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnFalse();
        $this->claude->shouldNotReceive('askWithUsage');
        $this->callRepository->shouldNotReceive('create');

        $this->expectException(SmsAssistantUnavailableException::class);
        $this->generator()->generate($this->request());
    }

    public function test_daily_limit_stops_calls(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldNotReceive('askWithUsage');
        $this->callRepository->shouldReceive('countWhere')->once()->andReturn(3);

        $this->expectException(SmsAssistantLimitReachedException::class);
        $this->generator(dailyLimit: 3)->generate($this->request());
    }

    public function test_empty_draft_is_a_failure(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer([
            'decision' => 'draft',
            'reply' => '  ',
            'reason' => null,
        ]));

        try {
            $this->generator()->generate($this->request());
            self::fail('A failure was expected');
        } catch (SmsAssistantFailedException) {
            self::assertSame('FAILED', $this->loggedCalls[0]['outcome']);
        }
    }

    public function test_claude_error_is_logged_as_a_failure(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andThrow(new EventAssistantFailedException('overloaded'));

        $this->expectException(SmsAssistantFailedException::class);

        try {
            $this->generator()->generate($this->request());
        } finally {
            self::assertSame('FAILED', $this->loggedCalls[0]['outcome']);
            self::assertNull($this->loggedCalls[0]['model']);
        }
    }

    public function test_long_reply_is_shortened_to_fit_one_message(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer([
            'decision' => 'draft',
            'reply' => str_repeat('Bonsoir ', 120),
            'reason' => null,
        ]));

        $result = $this->generator()->generate($this->request());

        self::assertLessThanOrEqual(640, mb_strlen($result->reply));
        self::assertStringEndsWith('...', $result->reply);
    }

    private function generator(int $dailyLimit = 150): SmsAssistantReplyGenerator
    {
        return new SmsAssistantReplyGenerator(
            $this->claude,
            $this->callRepository,
            new Repository(['services' => ['anthropic' => ['sms_assistant_daily_limit' => $dailyLimit]]]),
            new NullLogger,
        );
    }

    private function answer(array $data): ClaudeStructuredAnswerDTO
    {
        return new ClaudeStructuredAnswerDTO(data: $data, model: 'claude-opus-5-5', inputTokens: 1200, outputTokens: 80);
    }

    private function request(?string $personalLink = null, bool $tutoiement = false): SmsAssistantRequestDTO
    {
        return new SmsAssistantRequestDTO(
            organizerId: 4,
            conversationId: 12,
            trigger: SmsAssistantTrigger::INBOUND,
            organizerName: 'Innocent Event',
            timezone: 'Indian/Mayotte',
            instructions: 'Tutoie les clients.',
            contactName: null,
            thread: collect([new SmsAssistantThreadMessageDTO(
                author: SmsMessageAuthorType::CONTACT,
                content: 'Il reste des places ?',
                sentAt: '2026-10-21 10:00:00',
            )]),
            customerOrders: collect([new SmsCustomerOrderDTO(
                orderId: 1,
                publicId: 'O-1',
                eventId: 7,
                eventTitle: 'Halloween',
                eventStartDate: '2026-10-31 19:00:00',
                firstName: 'Anaïs',
                lastName: 'M',
                email: 'anais@example.com',
                totalGross: 15.0,
                currency: 'EUR',
                status: 'COMPLETED',
                createdAt: '2026-10-20 08:00:00',
            )]),
            events: collect([new SmsAssistantEventInfoDTO(
                title: 'Halloween',
                startsAt: 'samedi 31 octobre 2026 à 22h00',
                endsAt: 'dimanche 1 novembre 2026 à 05h00',
                venue: 'Le 5/5, Mamoudzou',
                url: 'https://ticket.picha.fr/event/7/halloween',
                tickets: [new SmsAssistantTicketInfoDTO(name: 'Vente flash', price: 15.0, currency: 'EUR', availability: SmsAssistantTicketInfoDTO::ON_SALE)],
            )]),
            personalLink: $personalLink,
            tutoiement: $tutoiement,
        );
    }

    public function test_organizer_in_tu_asks_for_tu_replies(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $sent = [];
        $this->claude->shouldReceive('askWithUsage')->twice()->andReturnUsing(function (string $system, array $content) use (&$sent) {
            $sent[] = $content[0]['text'];

            return $this->answer(['decision' => 'draft', 'reply' => 'Oui, il en reste !', 'reason' => null]);
        });

        $this->generator()->generate($this->request(tutoiement: true));
        $this->generator()->generate($this->request());

        self::assertStringContainsString("Tutoiement : l'organisateur tutoie ses clients.", $sent[0]);
        self::assertStringNotContainsString('Tutoiement :', $sent[1]);
    }

    public function test_plain_link_in_the_draft_becomes_the_personal_link(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturnUsing(function (string $system, array $content) use (&$sent) {
            $sent = $content[0]['text'];

            return $this->answer(['decision' => 'draft', 'reply' => 'Si si, il en reste ! Ta place à 15 euros est ici : https://innocent976.yt 🎃', 'reason' => null]);
        });

        $result = $this->generator()->generate($this->request(personalLink: 'https://innocent976.yt/?qr=iiekn0hg'));

        self::assertStringContainsString('Lien personnel du client : https://innocent976.yt/?qr=iiekn0hg', $sent);
        self::assertSame('Si si, il en reste ! Ta place à 15 euros est ici : https://innocent976.yt/?qr=iiekn0hg 🎃', $result->reply);
    }

    public function test_model_flags_whether_the_draft_may_leave_alone_and_writes_a_holding_reply(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturnUsing(function (string $system, array $content, array $schema) use (&$sentSchema) {
            $sentSchema = $schema;

            return $this->answer([
                'decision' => 'draft',
                'reply' => 'Ça commence à 22h.',
                'reason' => null,
                'auto_send_safe' => true,
                'holding_reply' => "Je vérifie avec l'équipe — je reviens vers vous très vite\n\nKultur'm via PICHA Ticket",
            ]);
        });

        $result = $this->generator()->generate($this->request());

        self::assertTrue($result->autoSendSafe);
        self::assertSame("Je vérifie avec l'équipe, je reviens vers vous très vite\n\nKultur'm via PICHA Ticket", $result->holdingReply);
        self::assertContains('auto_send_safe', $sentSchema['required']);
        self::assertContains('holding_reply', $sentSchema['required']);
    }

    public function test_needs_human_keeps_its_holding_reply_but_never_flags_auto_send(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer([
            'decision' => 'needs_human',
            'reply' => null,
            'reason' => "Demande d'invitation",
            'auto_send_safe' => true,
            'holding_reply' => "Bonne question, je vérifie avec l'équipe et je reviens vers vous très vite 🎶",
        ]));

        $result = $this->generator()->generate($this->request());

        self::assertSame(SmsAssistantOutcome::NEEDS_HUMAN, $result->outcome);
        self::assertFalse($result->autoSendSafe);
        self::assertSame("Bonne question, je vérifie avec l'équipe et je reviens vers vous très vite 🎶", $result->holdingReply);
    }

    public function test_holding_reply_with_a_link_or_a_missing_flag_is_ignored(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnTrue();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer([
            'decision' => 'draft',
            'reply' => 'Ça commence à 22h.',
            'reason' => null,
            'holding_reply' => 'Je vérifie, en attendant voici le lien https://ticket.picha.fr',
        ]));

        $result = $this->generator()->generate($this->request());

        self::assertFalse($result->autoSendSafe);
        self::assertNull($result->holdingReply);
    }
}
