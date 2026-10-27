<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\DomainObjects\LiveChatMessageDomainObject;
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

    public function test_model_bubbles_are_kept_in_order_and_cleaned(): void
    {
        $reply = $this->generator($this->claudeAnswering([
            'messages' => ['**Les portes ouvrent à 20h** — pas avant.', 'Tu veux le lien pour ta place ?'],
            'needs_follow_up' => false,
            'ask_contact' => false,
            'reason' => null,
            'intent' => 'none',
        ]), $this->calls())->generate($this->request());

        $this->assertSame(['Les portes ouvrent à 20h, pas avant.', 'Tu veux le lien pour ta place ?'], $reply->messages);
        $this->assertSame("Les portes ouvrent à 20h, pas avant.\nTu veux le lien pour ta place ?", $reply->text());
    }

    public function test_more_than_three_bubbles_are_merged_into_the_last_one(): void
    {
        $this->assertSame(
            ['Un.', 'Deux.', 'Trois. Quatre.'],
            LiveChatReplyGenerator::bubbles(['Un.', 'Deux.', '', 'Trois.', 'Quatre.'], null),
        );
    }

    public function test_an_older_single_reply_is_split_on_blank_lines_only(): void
    {
        $this->assertSame(
            ['Le DJ commence à 22h.', 'Tu as déjà ta place ?'],
            LiveChatReplyGenerator::bubbles(null, "Le DJ commence à 22h.\n\nTu as déjà ta place ?"),
        );
        $this->assertSame(
            ["Le DJ commence à 22h.\nOuverture à 20h."],
            LiveChatReplyGenerator::bubbles(null, "Le DJ commence à 22h.\nOuverture à 20h."),
        );
    }

    public function test_heyy_greetings_are_removed(): void
    {
        $this->assertSame(
            ["Bien reçu, je transmets ta demande à l'organisateur."],
            LiveChatReplyGenerator::bubbles(["Heyy masta ! Bien reçu, je transmets ta demande à l'organisateur."], null),
        );
        $this->assertSame(['Tu peux prendre ta place en ligne.'], LiveChatReplyGenerator::bubbles(['Heeyy tu peux prendre ta place en ligne.'], null));
        $this->assertSame(['Hey, la soirée commence à 20h.'], LiveChatReplyGenerator::bubbles(['Hey, la soirée commence à 20h.'], null));
    }

    public function test_an_exclamation_already_used_is_not_repeated(): void
    {
        $previous = ["Trop bien ! C'est quel type d'événement ?", "Merci, c'est noté."];

        $this->assertSame(
            ["C'est pour quelle date ?"],
            LiveChatReplyGenerator::bubbles(["Trop bien ! C'est pour quelle date ?"], null, $previous),
        );
        $this->assertSame(
            ['Merci, il te répond vite.'],
            LiveChatReplyGenerator::bubbles(['Merci, il te répond vite.'], null, $previous),
        );
        $this->assertSame('trop bien', LiveChatReplyGenerator::opening('Trop bien, ça me fait plaisir !'));
        $this->assertNull(LiveChatReplyGenerator::opening('Le billet est à 25 euros, frais en plus.'));
    }

    public function test_a_bubble_already_sent_is_not_sent_again(): void
    {
        $this->assertSame(
            ['Laisse-moi ton numéro, il te répond par SMS.'],
            LiveChatReplyGenerator::bubbles(
                ["Je transmets à l'organisateur.", 'Laisse-moi ton numéro, il te répond par SMS.'],
                null,
                ["Je transmets à l'organisateur."],
            ),
        );
    }

    public function test_context_gives_the_history_the_used_openings_and_the_hand_off(): void
    {
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(function (string $system, array $content, array $schema) {
                $text = $content[0]['text'];

                return $schema['properties']['messages']['type'] === 'array'
                    && in_array('messages', $schema['required'], true)
                    && ! isset($schema['properties']['reply'])
                    && str_contains($system, 'Tu es Anicka')
                    && str_contains($system, 'Jamais « Heyy »')
                    && str_contains($text, "Forme d'adresse : tutoiement")
                    && str_contains($text, "Demande déjà transmise à l'organisateur : oui")
                    && str_contains($text, "Message d'accueil déjà affiché par la fenêtre :\nBonjour ! Pose-moi ta question")
                    && str_contains($text, "Tes débuts de messages déjà employés (ne les réutilise pas) :\nbonjour\ntrop bien")
                    && str_contains($text, "Visiteur : J'aimerais organiser un anniversaire\nToi : Trop bien ! C'est pour quand ?\nVisiteur : 10 décembre");
            })
            ->andReturn(new ClaudeStructuredAnswerDTO(
                data: ['messages' => ["C'est noté pour le 10 décembre.", "L'organisateur te recontacte pour en parler."], 'needs_follow_up' => true, 'ask_contact' => false, 'reason' => 'Anniversaire privé', 'intent' => 'none'],
                model: 'claude-haiku-5-5',
                inputTokens: 10,
                outputTokens: 5,
            ));

        $reply = $this->generator($claude, $this->calls())->generate($this->request(
            contactKnown: true,
            tutoiement: true,
            handedOff: true,
            thread: [
                ['VISITOR', "J'aimerais organiser un anniversaire"],
                ['AGENT', "Trop bien ! C'est pour quand ?"],
                ['VISITOR', '10 décembre'],
            ],
        ));

        $this->assertCount(2, $reply->messages);
        $this->assertTrue($reply->needsFollowUp);
        $this->assertFalse($reply->askContact);
    }

    public function test_context_gives_the_facts_of_the_event_and_the_questions_waiting(): void
    {
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(function (string $system, array $content, array $schema) {
                $text = $content[0]['text'];

                return in_array('question_for_organizer', $schema['required'], true)
                    && str_contains($system, "Ne dis jamais que tu n'as pas l'info")
                    && str_contains($system, 'Jamais avant son 3e message')
                    && str_contains($text, '"faits_confirmes"')
                    && str_contains($text, 'Jah D One a été remplacé par Terrell Elymoor')
                    && str_contains($text, '"annonces_envoyees"')
                    && str_contains($text, 'Message du visiteur n° 2 dans cette conversation.')
                    && str_contains($text, 'Demande de coordonnées : pas encore')
                    && str_contains($text, "en attente de sa réponse (ne les repose pas) :\nLe parking est-il gratuit ?")
                    && str_contains($text, "Dernières publications de l'organisateur sur ses réseaux :\n[");
            })
            ->andReturn(new ClaudeStructuredAnswerDTO(
                data: ['messages' => ['Il est remplacé par Terrell Elymoor.'], 'needs_follow_up' => false, 'ask_contact' => false, 'reason' => null, 'intent' => 'none', 'question_for_organizer' => null],
                model: 'claude-haiku-5-5',
                inputTokens: 10,
                outputTokens: 5,
            ));

        $reply = $this->generator($claude, $this->calls())->generate(new LiveChatRequestDTO(
            organizerId: 4,
            sessionId: 1,
            smsConversationId: null,
            organizerName: 'Innocent Event',
            timezone: 'Indian/Mayotte',
            instructions: null,
            tutoiement: true,
            contactKnown: false,
            currentEventId: 7,
            thread: collect([(new LiveChatMessageDomainObject)->setAuthorType('VISITOR')->setContent('jah d one ne sera pas présent ?')]),
            events: collect([new \HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO(
                title: 'Halloween',
                startsAt: '2026-10-31 20:00',
                endsAt: null,
                venue: '5/5',
                url: 'https://innocent976.yt',
                tickets: [],
                id: 7,
            )]),
            visitorMessageNumber: 2,
            contactPolicy: 'pas encore (avant le 3e message du visiteur)',
            pendingQuestions: ['Le parking est-il gratuit ?'],
            knowledge: [7 => [
                'faits_confirmes' => [['reponse' => 'Jah D One a été remplacé par Terrell Elymoor', 'date' => '27 octobre 2026']],
                'annonces' => [['date' => '21 octobre 2026', 'texte' => 'Le 31 octobre c\'est Halloween']],
            ]],
            publications: [['date' => '26 octobre 2026', 'texte' => 'Programme complet']],
        ));

        $this->assertNull($reply->questionForOrganizer);
        $this->assertSame(['Il est remplacé par Terrell Elymoor.'], $reply->messages);
    }

    public function test_a_holding_line_replaces_missing_information_and_the_question_goes_to_the_organizer(): void
    {
        $reply = $this->generator($this->claudeAnswering([
            'messages' => ["Je n'ai pas l'info pour Jah D One."],
            'needs_follow_up' => false,
            'ask_contact' => false,
            'reason' => null,
            'intent' => 'none',
            'question_for_organizer' => null,
        ]), $this->calls())->generate($this->request(tutoiement: true, thread: [['VISITOR', 'jah d one ne sera pas présent ?']]));

        $this->assertSame(['Je demande confirmation et je te redis ici.'], $reply->messages);
        $this->assertSame('jah d one ne sera pas présent ?', $reply->questionForOrganizer);
    }

    public function test_an_answer_without_any_text_fails(): void
    {
        $this->expectException(\HiEvents\Exceptions\SmsAssistantFailedException::class);

        $this->generator($this->claudeAnswering([
            'messages' => ['', '   '],
            'needs_follow_up' => false,
            'ask_contact' => false,
            'reason' => null,
            'intent' => 'none',
        ]), $this->calls())->generate($this->request());
    }

    private function claudeAnswering(array $data)
    {
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $claude->shouldReceive('askWithUsage')->once()->andReturn(new ClaudeStructuredAnswerDTO(
            data: $data,
            model: 'claude-haiku-5-5',
            inputTokens: 10,
            outputTokens: 5,
        ));

        return $claude;
    }

    private function calls()
    {
        $calls = Mockery::mock(SmsAssistantCallRepositoryInterface::class);
        $calls->shouldReceive('countWhere')->andReturn(0);
        $calls->shouldReceive('create');

        return $calls;
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

    /**
     * @param  list<array{0: string, 1: string}>  $thread
     */
    private function request(
        bool $contactKnown = false,
        bool $refundAvailable = false,
        bool $tutoiement = false,
        bool $handedOff = false,
        array $thread = [],
    ): LiveChatRequestDTO {
        return new LiveChatRequestDTO(
            organizerId: 1,
            sessionId: 1,
            smsConversationId: null,
            organizerName: 'Innocent Event',
            timezone: 'Indian/Mayotte',
            instructions: null,
            tutoiement: $tutoiement,
            contactKnown: $contactKnown,
            currentEventId: null,
            thread: collect($thread)->map(fn (array $line) => (new LiveChatMessageDomainObject)
                ->setAuthorType($line[0])
                ->setContent($line[1])),
            events: collect(),
            refundAvailable: $refundAvailable,
            greeting: LiveChatService::greeting($tutoiement),
            handedOff: $handedOff,
        );
    }
}
