<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\Jobs\Sms\GenerateOrganizerAssistantReplyJob;
use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Mail\Sms\PlatformSupervisionAlertMail;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\LiveChat\Question\LiveChatQuestionService;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * A question the assistant cannot answer goes to the organizer by SMS (« Q12 »), the answer comes back to the visitor
 * and becomes a confirmed fact of the event. The model is always mocked.
 */
class LiveChatQuestionFlowTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const LINE_NUMBER = '+262639000000';

    private const ORGANIZER = '+262639250206';

    private const COPY = '+262692293622';

    private const SUPERVISOR = '+262639780773';

    private const VISITOR = '+262639444555';

    private const QUESTION = 'jah d one ne sera pas présent ?';

    private const FACT = 'Jah D One ne sera pas présent, il est remplacé par Terrell Elymoor.';

    private Event $event;

    private int $organizerId;

    private ClaudeStructuredOutputClient|MockInterface $claude;

    private OrganizerSmsSetting $line;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event] = $this->createEventWithProduct(price: 15.00);
        $this->organizerId = (int) $this->event->organizer_id;
        DB::table('events')->where('id', $this->event->id)->update([
            'title' => 'Halloween',
            'timezone' => 'Indian/Mayotte',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(10)->addHours(4),
        ]);

        Carbon::setTestNow(Carbon::now('UTC')->addDay()->setTime(7, 0));
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.live_chat.model' => 'claude-haiku-5-5',
            'services.platform_supervisor.phones' => self::SUPERVISOR,
            'services.sms_watchdog.alert_email' => 'veille@picha.test',
        ]);
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);

        $this->line = OrganizerSmsSetting::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'uk_line',
            'from_number' => self::LINE_NUMBER,
        ]);

        $this->mock(HttpSmsClient::class, function (MockInterface $client) {
            $client->shouldReceive('sendMessage')->andReturnUsing(function (string $apiKey, string $from, string $to, string $content) {
                $this->sent[] = compact('to', 'content');

                return 'provider-out-'.Str::random(16);
            });
        });
        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');

        $teams = app(OrganizerTeamContactService::class);
        $teams->save($this->organizerId, self::ORGANIZER, 'Innocent', OrganizerTeamContactRole::ORGANIZER);
        $teams->save($this->organizerId, self::COPY, 'Marina', OrganizerTeamContactRole::COPY);

        $this->claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $this->claude->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(ClaudeStructuredOutputClient::class, $this->claude);

        Bus::fake([SendSmsInboundAlertJob::class, GenerateOrganizerAssistantReplyJob::class]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_missing_information_is_never_said_and_the_question_waits_for_contact_details(): void
    {
        $sessionId = $this->openChat();
        $this->chatAnswer(["Je n'ai pas l'info pour Jah D One. Le D est bien au programme samedi."]);

        $response = $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])
            ->assertOk()
            ->assertJsonPath('data.pending_question', true)
            ->assertJsonPath('data.ask_contact', true);

        $agent = collect($response->json('data.messages'))->where('author_type', 'AGENT')->pluck('content')->all();
        $this->assertSame([
            'Le D est bien au programme samedi.',
            'Je demande confirmation et je vous redis ici.',
            "Je la transmets à l'organisateur. Vous me laissez votre numéro ou votre e-mail pour qu'il puisse vous répondre ?",
        ], $agent);

        $question = DB::table('live_chat_questions')->where('organizer_id', $this->organizerId)->first();
        $this->assertSame('AWAITING_CONTACT', $question->status);
        $this->assertSame(1, (int) $question->contact_requests);
        $this->assertSame(self::QUESTION, $question->question);
        $this->assertSame((int) $this->event->id, (int) $question->event_id);
        $this->assertSame([], $this->sent, 'nothing goes to the organizer before the visitor can be reached');
    }

    public function test_a_number_typed_in_the_chat_sends_the_question_with_it(): void
    {
        $sessionId = $this->openChat();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'À partir de quel âge les enfants peuvent-ils participer ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'à partir de quel âge les enfants peuvent-ils participer?'])->assertOk();

        $response = $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'oui c\'est le 06 39 44 45 55'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', false)
            ->assertJsonPath('data.pending_question', true);

        $this->assertSame(
            ["Merci, je transmets votre question à l'organisateur, il vous répond au ".self::VISITOR.'.'],
            collect($response->json('data.messages'))->where('author_type', 'AGENT')->pluck('content')->values()->all(),
        );
        $question = DB::table('live_chat_questions')->first();
        $this->assertSame('AWAITING', $question->status);
        $this->assertSame(self::ORGANIZER, $question->notified_phones);
        $this->assertSame(self::VISITOR, DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('visitor_phone'));

        $this->assertSame([self::ORGANIZER], array_column($this->sent, 'to'));
        $this->assertSame(
            "Q{$question->id}, Anicka te transmet la question d'un visiteur (Halloween) :\n« À partir de quel âge les enfants peuvent-ils participer ? »\n"
            .'Coordonnées du visiteur : '.self::VISITOR."\nRéponds à ce SMS, ou écris Q{$question->id} suivi de la réponse. Je la transmets au visiteur.",
            $this->sent[0]['content'],
        );
    }

    public function test_the_contact_form_sends_the_held_question(): void
    {
        $sessionId = $this->openChat();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();

        $this->chat('post', '/public/live-chat/contact', $sessionId, ['email' => 'Visiteur@Example.com'])
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', "Merci, je transmets votre question à l'organisateur, il vous répond par e-mail à visiteur@example.com.")
            ->assertJsonPath('data.pending_question', true);

        $this->assertSame('AWAITING', DB::table('live_chat_questions')->value('status'));
        $this->assertSame([self::ORGANIZER], array_column($this->sent, 'to'));
        $this->assertStringContainsString('Coordonnées du visiteur : visiteur@example.com', $this->sent[0]['content']);
    }

    public function test_contact_is_asked_again_once_then_the_question_leaves_without_it(): void
    {
        $sessionId = $this->openChat();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();

        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], 'Coordonnées pour la question transmise : Ta question')
                && str_contains($content[0]['text'], 'redemande-les une seule fois'))
            ->andReturn($this->answer(['messages' => ["C'est à Mamoudzou."], 'needs_follow_up' => false, 'ask_contact' => false, 'reason' => null, 'intent' => 'none', 'question_for_organizer' => null]));
        $second = $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => "et c'est où ?"])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);
        $this->assertSame(
            ["C'est à Mamoudzou.", "Vous pouvez me laisser un numéro ou un e-mail juste en dessous ? C'est pour que l'organisateur puisse vous répondre."],
            collect($second->json('data.messages'))->where('author_type', 'AGENT')->pluck('content')->values()->all(),
        );
        $this->assertSame(2, (int) DB::table('live_chat_questions')->value('contact_requests'));
        $this->assertSame([], $this->sent);

        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], 'vient de partir chez l\'organisateur sans coordonnées'))
            ->andReturn($this->answer(['messages' => ['Bonne soirée à vous.'], 'needs_follow_up' => false, 'ask_contact' => false, 'reason' => null, 'intent' => 'none', 'question_for_organizer' => null]));
        $third = $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'ok merci'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true)
            ->assertJsonPath('data.pending_question', true);

        $this->assertSame(
            ['Bonne soirée à vous.', "Pas de souci, je transmets quand même votre question à l'organisateur. Sans numéro ni e-mail, on ne pourra pas vous prévenir : sa réponse s'affichera ici, si vous revenez sur cette page depuis le même appareil."],
            collect($third->json('data.messages'))->where('author_type', 'AGENT')->pluck('content')->values()->all(),
        );
        $this->assertSame('AWAITING', DB::table('live_chat_questions')->value('status'));
        $this->assertSame([self::ORGANIZER], array_column($this->sent, 'to'));
        $this->assertStringContainsString("Le visiteur n'a pas laissé de coordonnées : ta réponse s'affichera seulement dans son chat.", $this->sent[0]['content']);
    }

    public function test_a_refusal_sends_the_question_at_once_and_says_where_the_answer_will_be(): void
    {
        $sessionId = $this->openChat();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();
        $this->chatAnswer(['Très bien.']);

        $response = $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'non merci'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', false);

        $this->assertStringContainsString(
            "sa réponse s'affichera ici",
            (string) collect($response->json('data.messages'))->where('author_type', 'AGENT')->pluck('content')->last(),
        );
        $this->assertNotNull(DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('contact_declined_at'));
        $this->assertSame('AWAITING', DB::table('live_chat_questions')->value('status'));
        $this->assertSame([self::ORGANIZER], array_column($this->sent, 'to'));
    }

    public function test_a_visitor_who_left_has_the_question_sent_after_ten_minutes(): void
    {
        $sessionId = $this->openChat();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();
        $service = app(LiveChatQuestionService::class);

        $service->processDue(Carbon::now('UTC')->addMinutes(9));
        $this->assertSame([], $this->sent);

        $service->processDue(Carbon::now('UTC')->addMinutes(10));
        $this->assertSame('AWAITING', DB::table('live_chat_questions')->value('status'));
        $this->assertSame([self::ORGANIZER], array_column($this->sent, 'to'));
        $this->assertStringContainsString("Le visiteur n'a pas laissé de coordonnées", $this->sent[0]['content']);
    }

    public function test_a_hand_over_asks_for_contact_details_from_the_first_message(): void
    {
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer([
            'messages' => ["Je fais suivre votre demande à l'organisateur."],
            'needs_follow_up' => true,
            'ask_contact' => false,
            'reason' => 'demande de partenariat',
            'intent' => 'none',
            'question_for_organizer' => null,
        ]));

        $response = $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'on voudrait un partenariat avec vous'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);

        $this->assertSame(
            ["Je fais suivre votre demande à l'organisateur.", "Vous me laissez votre numéro ou votre e-mail pour que l'organisateur puisse vous répondre ?"],
            collect($response->json('data.messages'))->where('author_type', 'AGENT')->pluck('content')->values()->all(),
        );
    }

    public function test_contact_details_written_in_a_message_are_recognised(): void
    {
        $this->assertSame(['phone' => '+262639444555', 'email' => null], LiveChatService::extractContact('mon num 0639 44 45 55'));
        $this->assertSame(['phone' => null, 'email' => 'jo@picha.fr'], LiveChatService::extractContact('écrivez à Jo@Picha.fr.'));
        $this->assertNull(LiveChatService::extractContact('pour 4 personnes le 12'));
        $this->assertSame(['Déjà un e-mail ?'], LiveChatService::withContactRequest(['Déjà un e-mail ?'], 'X'));
        $this->assertSame(['a', 'b', 'c X'], LiveChatService::withContactRequest(['a', 'b', 'c'], 'X'));
    }

    public function test_the_reply_of_the_organizer_reaches_the_visitor_and_becomes_a_fact(): void
    {
        $sessionId = $this->openChatWithContact();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent à Halloween ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();
        DB::table('organizer_sms_settings')->where('id', $this->line->id)->update(['organizer_assistant_enabled' => true]);
        $questionId = (int) DB::table('live_chat_questions')->value('id');
        $this->sent = [];

        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(fn (string $system, array $content) => str_contains($system, 'Plus tôt, un visiteur a posé une question')
                && str_contains($content[0]['text'], "Question transmise à l'organisateur :\nJah D One sera-t-il présent à Halloween ?")
                && str_contains($content[0]['text'], "Réponse de l'organisateur :\nnon il est remplacé par terrell"))
            ->andReturn($this->answer([
                'messages' => ['Pour Jah D One, il ne sera pas là.', 'Il est remplacé par Terrell Elymoor.'],
                'fact' => self::FACT,
            ]));

        $this->receive(self::ORGANIZER, 'non il est remplacé par terrell', 'in-1');

        $question = DB::table('live_chat_questions')->where('id', $questionId)->first();
        $this->assertSame('RELAYED', $question->status);
        $this->assertSame('non il est remplacé par terrell', $question->answer);
        $this->assertSame(self::ORGANIZER, $question->answered_by_phone);

        $fact = DB::table('event_assistant_facts')->where('event_id', $this->event->id)->first();
        $this->assertSame(self::FACT, $fact->answer);
        $this->assertSame('ORGANIZER', $fact->source);
        $this->assertSame($questionId, (int) $fact->live_chat_question_id);
        $this->assertNotNull($fact->confirmed_at);

        $this->assertSame([self::VISITOR], array_column($this->sent, 'to'), 'no automatic SMS goes back to the organizer');
        $this->assertSame("Réponse à votre question sur Halloween :\nPour Jah D One, il ne sera pas là.\nIl est remplacé par Terrell Elymoor.", $this->sent[0]['content']);
        Bus::assertNotDispatched(SendSmsInboundAlertJob::class);
        Bus::assertNotDispatched(GenerateOrganizerAssistantReplyJob::class);

        $this->chat('get', '/public/live-chat/messages', $sessionId)
            ->assertOk()
            ->assertJsonPath('data.pending_question', false)
            ->assertJsonPath('data.messages.2.content', 'Pour Jah D One, il ne sera pas là.')
            ->assertJsonPath('data.messages.3.content', 'Il est remplacé par Terrell Elymoor.');

        $next = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], '"faits_confirmes"')
                && str_contains($content[0]['text'], self::FACT))
            ->andReturn($this->answer([
                'messages' => ['Non, il est remplacé par Terrell Elymoor.'],
                'needs_follow_up' => false,
                'ask_contact' => false,
                'reason' => null,
                'intent' => 'none',
                'question_for_organizer' => null,
            ]));
        $this->chat('post', '/public/live-chat/messages', $next, ['message' => 'Jah D One est là samedi ?'])
            ->assertOk()
            ->assertJsonPath('data.pending_question', false);
        $this->assertSame(1, DB::table('live_chat_questions')->count());
    }

    public function test_an_answer_with_the_reference_is_relayed_even_when_the_model_fails(): void
    {
        $sessionId = $this->openChatWithContact();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();
        $questionId = (int) DB::table('live_chat_questions')->value('id');
        $this->claude->shouldReceive('askWithUsage')->once()->andThrow(new \HiEvents\Exceptions\EventAssistantFailedException('boom'));

        $this->receive(self::ORGANIZER, "q{$questionId} : non, remplacé par Terrell", 'in-1');

        $this->assertSame('RELAYED', DB::table('live_chat_questions')->where('id', $questionId)->value('status'));
        $this->assertSame('non, remplacé par Terrell', DB::table('live_chat_questions')->where('id', $questionId)->value('answer'));
        $this->assertSame(
            "J'ai la réponse à votre question : non, remplacé par Terrell",
            DB::table('live_chat_messages')->where('author_type', 'AGENT')->orderByDesc('id')->value('content'),
        );
        $this->assertSame('non, remplacé par Terrell', DB::table('event_assistant_facts')->value('answer'));
    }

    public function test_a_reply_is_not_taken_as_the_answer_once_the_organizer_wrote_something_else(): void
    {
        $sessionId = $this->openChatWithContact();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();
        $questionId = (int) DB::table('live_chat_questions')->value('id');

        $this->receive(self::ORGANIZER, 'ok', 'in-1');
        $this->receive(self::ORGANIZER, 'Il est remplacé', 'in-2');

        $this->assertSame('AWAITING', DB::table('live_chat_questions')->where('id', $questionId)->value('status'));
    }

    public function test_copy_contacts_and_acknowledgements_do_not_answer(): void
    {
        $sessionId = $this->openChatWithContact();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();
        $questionId = (int) DB::table('live_chat_questions')->value('id');

        $this->receive(self::COPY, 'Il est remplacé', 'in-1');
        $this->receive(self::ORGANIZER, 'ok', 'in-2');
        $this->receive(self::COPY, "Q{$questionId} il est remplacé", 'in-3');

        $this->assertSame('AWAITING', DB::table('live_chat_questions')->where('id', $questionId)->value('status'));
        $this->assertSame(0, DB::table('event_assistant_facts')->count());
    }

    public function test_one_reminder_after_two_hours_and_the_supervision_is_alerted(): void
    {
        $sessionId = $this->openChatWithContact();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();
        $questionId = (int) DB::table('live_chat_questions')->value('id');
        $this->sent = [];
        $service = app(LiveChatQuestionService::class);

        $this->assertSame(0, $service->processDue(Carbon::now('UTC')->addMinutes(119)));
        $this->assertSame(1, $service->processDue(Carbon::now('UTC')->addHours(2)));
        $this->assertSame(0, $service->processDue(Carbon::now('UTC')->addHours(5)));

        $this->assertSame(self::ORGANIZER, $this->sent[0]['to']);
        $this->assertStringStartsWith("Rappel Q{$questionId}, le visiteur attend toujours (Halloween)", $this->sent[0]['content']);
        $this->assertStringContainsString('Coordonnées du visiteur : '.self::VISITOR, $this->sent[0]['content']);
        $this->assertSame(self::SUPERVISOR, $this->sent[1]['to']);
        $this->assertStringStartsWith("Alerte PICHA : Q{$questionId}", $this->sent[1]['content']);
        $this->assertCount(2, $this->sent);
        Mail::assertQueued(PlatformSupervisionAlertMail::class);
    }

    public function test_a_question_at_night_is_reminded_from_8_in_mayotte(): void
    {
        $service = app(LiveChatQuestionService::class);
        $night = Carbon::parse('2026-10-31 18:30:00', 'UTC');

        $this->assertSame('2026-11-01 05:00:00', $service->nextReminderAt($night)->toDateTimeString());
        $this->assertSame('2026-10-31 09:00:00', $service->nextReminderAt(Carbon::parse('2026-10-31 07:00:00', 'UTC'))->toDateTimeString());
    }

    public function test_the_same_question_is_not_sent_twice(): void
    {
        $sessionId = $this->openChatWithContact();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])->assertOk();

        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(fn (string $system, array $content) => str_contains(
                $content[0]['text'],
                "Questions déjà posées à l'organisateur, en attente de sa réponse (ne les repose pas) :\nJah D One sera-t-il présent ?",
            ))
            ->andReturn($this->answer([
                'messages' => ["Je n'ai pas encore la réponse, j'attends sa confirmation."],
                'needs_follow_up' => false,
                'ask_contact' => false,
                'reason' => null,
                'intent' => 'none',
                'question_for_organizer' => 'Jah D One sera-t-il présent ?',
            ]));
        $this->sent = [];

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'non'])->assertOk();

        $this->assertSame(1, DB::table('live_chat_questions')->count());
        $this->assertSame([], $this->sent);
    }

    public function test_without_sms_line_the_question_falls_back_on_the_usual_hand_over(): void
    {
        DB::table('organizer_sms_settings')->where('id', $this->line->id)->update(['api_key' => null, 'from_number' => null]);
        $sessionId = $this->openChatWithContact();
        $this->chatAnswer(['Je vérifie et je reviens vers vous ici.'], question: 'Jah D One sera-t-il présent ?');

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => self::QUESTION])
            ->assertOk()
            ->assertJsonPath('data.pending_question', false);

        $this->assertSame(0, DB::table('live_chat_questions')->count());
        $this->assertTrue((bool) DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('needs_follow_up'));
    }

    private function chatAnswer(array $messages, ?string $question = null): void
    {
        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(fn (string $system) => str_contains($system, 'Tu es Anicka. Tu réponds en direct'))
            ->andReturn($this->answer([
                'messages' => $messages,
                'needs_follow_up' => false,
                'ask_contact' => false,
                'reason' => null,
                'intent' => 'none',
                'question_for_organizer' => $question,
            ]));
    }

    private function answer(array $data): ClaudeStructuredAnswerDTO
    {
        return new ClaudeStructuredAnswerDTO(data: $data, model: 'claude-haiku-5-5', inputTokens: 100, outputTokens: 20);
    }

    private function receive(string $contact, string $content, string $messageId): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: HandleHttpSmsWebhookHandler::EVENT_RECEIVED,
            payload: json_encode(['data' => [
                'message_id' => $messageId,
                'contact' => $contact,
                'owner' => self::LINE_NUMBER,
                'content' => $content,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ]]),
        ));
    }

    private function openChatWithContact(): string
    {
        $sessionId = $this->openChat();
        DB::table('live_chat_sessions')->where('public_id', $sessionId)->update(['visitor_phone' => self::VISITOR]);

        return $sessionId;
    }

    private function openChat(): string
    {
        return (string) $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions", ['event_id' => $this->event->id])
            ->assertCreated()
            ->json('data.session_id');
    }

    private function chat(string $method, string $uri, string $sessionId, array $data = []): TestResponse
    {
        return $this->withHeaders(['X-Chat-Session' => $sessionId])->json(strtoupper($method), $uri, $data);
    }
}
