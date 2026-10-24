<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Repository\Interfaces\SmsAssistantCallRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatRequestDTO;
use HiEvents\Services\Domain\LiveChat\LiveChatReplyGenerator;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use Illuminate\Config\Repository;
use Mockery;
use Psr\Log\NullLogger;
use Tests\TestCase;

class LiveChatReplyGeneratorTest extends TestCase
{
    public function test_forbidden_characters_and_markdown_are_removed(): void
    {
        $this->assertSame(
            "Le concert commence à 20h, ouverture des portes à 19h.\nTarif unique, 15 euros.",
            LiveChatReplyGenerator::cleanText("**Le concert** commence à 20h — ouverture des portes à 19h.\n• Tarif unique · 15 euros."),
        );
        $this->assertSame('Infos pratiques, parking gratuit', LiveChatReplyGenerator::cleanText('## Infos pratiques – parking gratuit'));
    }

    public function test_relay_line_uses_the_generic_default(): void
    {
        $this->assertSame("Réponse immédiate, l'organisateur peut prendre le relais", LiveChatService::relayLine(null));
        $this->assertSame("Réponse immédiate, l'équipe Konka peut prendre le relais", LiveChatService::relayLine("l'équipe Konka"));
    }

    public function test_haiku_models_are_called_without_server_side_fallback(): void
    {
        $this->assertFalse(ClaudeStructuredOutputClient::supportsServerSideFallback('claude-haiku-5-5'));
        $this->assertTrue(ClaudeStructuredOutputClient::supportsServerSideFallback('claude-opus-5-5'));
    }

    public function test_contact_is_never_asked_again_once_known(): void
    {
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $claude->shouldReceive('askWithUsage')->andReturn(new ClaudeStructuredAnswerDTO(
            data: ['reply' => 'Je transmets.', 'needs_follow_up' => true, 'ask_contact' => true, 'reason' => 'Litige'],
            model: 'claude-haiku-5-5',
            inputTokens: 10,
            outputTokens: 5,
        ));
        $calls = Mockery::mock(SmsAssistantCallRepositoryInterface::class);
        $calls->shouldReceive('countWhere')->andReturn(0);
        $calls->shouldReceive('create')->once();

        $reply = $this->generator($claude, $calls)->generate($this->request(contactKnown: true));

        $this->assertTrue($reply->needsFollowUp);
        $this->assertFalse($reply->askContact);
        $this->assertSame('Litige', $reply->reason);
    }

    public function test_refund_intent_is_kept_only_when_the_organizer_offers_automatic_refunds(): void
    {
        $answer = new ClaudeStructuredAnswerDTO(
            data: ['reply' => 'On vérifie ta commande.', 'needs_follow_up' => true, 'ask_contact' => true, 'reason' => 'Remboursement', 'intent' => 'refund_request'],
            model: 'claude-haiku-5-5',
            inputTokens: 10,
            outputTokens: 5,
        );
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content, array $schema) => in_array('intent', $schema['required'], true)
                && str_contains($content[0]['text'], 'Remboursement automatique possible : oui'))
            ->once()
            ->andReturn($answer);
        $claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], 'Remboursement automatique possible : non'))
            ->once()
            ->andReturn($answer);
        $calls = Mockery::mock(SmsAssistantCallRepositoryInterface::class);
        $calls->shouldReceive('countWhere')->andReturn(0);
        $calls->shouldReceive('create');

        $offered = $this->generator($claude, $calls)->generate($this->request(refundAvailable: true));
        $this->assertTrue($offered->refundRequest);
        $this->assertFalse($offered->needsFollowUp);
        $this->assertFalse($offered->askContact);

        $notOffered = $this->generator($claude, $calls)->generate($this->request(refundAvailable: false));
        $this->assertFalse($notOffered->refundRequest);
        $this->assertTrue($notOffered->needsFollowUp);
        $this->assertTrue($notOffered->askContact);
    }

    public function test_daily_limit_blocks_the_call(): void
    {
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $claude->shouldNotReceive('askWithUsage');
        $calls = Mockery::mock(SmsAssistantCallRepositoryInterface::class);
        $calls->shouldReceive('countWhere')
            ->withArgs(fn (array $where) => in_array('LIVE_CHAT', $where, true))
            ->andReturn(300);

        $this->expectException(SmsAssistantLimitReachedException::class);

        $this->generator($claude, $calls)->generate($this->request());
    }

    private function generator($claude, $calls): LiveChatReplyGenerator
    {
        return new LiveChatReplyGenerator(
            $claude,
            $calls,
            new Repository(['services' => ['live_chat' => ['daily_limit' => 300, 'model' => 'claude-haiku-5-5']]]),
            new NullLogger,
        );
    }

    private function request(bool $contactKnown = false, bool $refundAvailable = false): LiveChatRequestDTO
    {
        return new LiveChatRequestDTO(
            organizerId: 1,
            sessionId: 1,
            smsConversationId: null,
            organizerName: 'Innocent Event',
            timezone: 'Indian/Mayotte',
            instructions: null,
            tutoiement: false,
            contactKnown: $contactKnown,
            currentEventId: null,
            thread: collect(),
            events: collect(),
            refundAvailable: $refundAvailable,
        );
    }
}
