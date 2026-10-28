<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Mail\LiveChat\LiveChatContactAlertMail;
use HiEvents\Models\Event;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class LiveChatApiTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'Password-chat-1';

    private Event $event;

    private mixed $user;

    private int $organizerId;

    private ClaudeStructuredOutputClient|MockInterface $claude;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event, , , $this->user] = $this->createEventWithProduct(userPassword: self::PASSWORD);
        $this->organizerId = (int) $this->event->organizer_id;

        config([
            'services.anthropic.api_key' => 'test-key',
            'services.live_chat.model' => 'claude-haiku-5-5',
        ]);

        $this->claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $this->claude->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(ClaudeStructuredOutputClient::class, $this->claude);

        Bus::fake([SendSmsInboundAlertJob::class]);
        Mail::fake();
    }

    public function test_chat_endpoints_are_unusable_when_the_setting_is_off(): void
    {
        $this->settings(enabled: false);

        $this->getJson("/public/organizers/{$this->organizerId}/live-chat")
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.organizer_name', null);
        $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions")->assertNotFound();

        $this->settings(enabled: true);
        $sessionId = $this->openChat();
        DB::table('organizer_sms_settings')->where('organizer_id', $this->organizerId)->update(['live_chat_enabled' => false]);

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Bonjour'])->assertNotFound();
        $this->chat('get', '/public/live-chat/messages', $sessionId)->assertNotFound();
        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '0639000000'])->assertNotFound();
    }

    public function test_organizer_without_settings_has_the_chat_on_by_default(): void
    {
        $this->getJson("/public/organizers/{$this->organizerId}/live-chat")
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.relay_line', "Réponse immédiate, l'organisateur peut prendre le relais");

        $this->openChat();
        $this->assertSame(0, DB::table('organizer_sms_settings')->where('organizer_id', $this->organizerId)->count());
    }

    public function test_new_sms_settings_have_the_chat_on(): void
    {
        DB::table('organizer_sms_settings')->insert([
            'account_id' => DB::table('organizers')->where('id', $this->organizerId)->value('account_id'),
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(48),
            'webhook_signing_key' => encrypt(Str::random(32)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue((bool) DB::table('organizer_sms_settings')->where('organizer_id', $this->organizerId)->value('live_chat_enabled'));
        $this->getJson("/public/organizers/{$this->organizerId}/live-chat")->assertJsonPath('data.enabled', true);
    }

    public function test_phone_of_an_organizer_without_sms_line_is_handed_over_by_email(): void
    {
        DB::table('organizers')->where('id', $this->organizerId)->update(['email' => 'orga@example.com']);
        $sessionId = $this->openChat();

        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '0639 12 34 56'])
            ->assertOk()
            ->assertJsonPath('data.contact_given', true)
            ->assertJsonPath('data.messages.0.content', "Merci, c'est noté. L'organisateur vous recontacte au +262639123456.");

        $this->assertSame(0, DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->count());
        Mail::assertQueued(LiveChatContactAlertMail::class, fn (LiveChatContactAlertMail $mail) => $mail->hasTo('orga@example.com'));
    }

    public function test_organizer_settings_without_line_hand_over_a_phone_by_email(): void
    {
        $this->settings(withLine: false);
        DB::table('organizers')->where('id', $this->organizerId)->update(['email' => 'orga@example.com']);
        $sessionId = $this->openChat();

        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '0639123456'])->assertOk();

        $this->assertSame(0, DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->count());
        Mail::assertQueued(LiveChatContactAlertMail::class);
    }

    public function test_chat_is_off_when_the_assistant_is_not_configured(): void
    {
        $this->settings();
        $this->claude->shouldReceive('isConfigured')->andReturn(false);

        $this->getJson("/public/organizers/{$this->organizerId}/live-chat")->assertJsonPath('data.enabled', false);
    }

    public function test_config_shows_the_relay_line(): void
    {
        $this->settings(relayLabel: "un membre de l'association");

        $this->getJson("/public/organizers/{$this->organizerId}/live-chat")
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.organizer_name', 'Test Organizer')
            ->assertJsonPath('data.relay_line', "Réponse immédiate, un membre de l'association peut prendre le relais")
            ->assertJsonPath('data.max_message_length', 800);
    }

    public function test_session_creation_returns_an_opaque_id_in_the_body(): void
    {
        $this->settings();

        $response = $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions", ['event_id' => $this->event->id])
            ->assertCreated()
            ->assertJsonPath('data.messages', [])
            ->assertJsonPath('data.contact_given', false);

        $sessionId = $response->json('data.session_id');
        $this->assertSame(48, strlen($sessionId));
        $this->assertStringContainsString('Posez-moi votre question', $response->json('data.greeting'));

        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertSame($this->organizerId, (int) $session->organizer_id);
        $this->assertSame($this->event->id, (int) $session->event_id);
        $this->assertNotSame('127.0.0.1', $session->ip_hash);
        $this->assertSame(64, strlen($session->ip_hash));
    }

    public function test_event_of_another_organizer_is_ignored(): void
    {
        $this->settings();
        [$otherEvent] = $this->createEventWithProduct();

        $sessionId = $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions", ['event_id' => $otherEvent->id])
            ->assertCreated()
            ->json('data.session_id');

        $this->assertNull(DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('event_id'));
    }

    public function test_message_flow_answers_with_the_model_and_logs_usage(): void
    {
        $this->settings();
        DB::table('events')->where('id', $this->event->id)->update([
            'timezone' => 'Indian/Mayotte',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(10)->addHours(4),
        ]);
        DB::table('event_settings')->where('event_id', $this->event->id)->update([
            'event_pages' => json_encode(['faq' => [['question' => 'Parking ?', 'answer' => '<p>Oui, gratuit</p>']]]),
        ]);
        $sessionId = $this->openChat($this->event->id);

        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(function (string $system, array $content, array $schema, array $tools = [], ?string $model = null) {
                return $model === 'claude-haiku-5-5'
                    && str_contains($content[0]['text'], 'Test Event')
                    && str_contains($content[0]['text'], '"page_consultee": true')
                    && str_contains($content[0]['text'], '"reponse": "Oui, gratuit"')
                    && str_contains($content[0]['text'], "Consigne de l'organisateur :\nDites bonjour")
                    && str_contains($content[0]['text'], 'Visiteur : Quel est le prix du billet ?')
                    && in_array('ask_contact', $schema['required'], true);
            })
            ->andReturn($this->answer('Le billet est à 25 euros — frais de service en plus • au paiement.'));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Quel est le prix du billet ?'])
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.author_type', 'VISITOR')
            ->assertJsonPath('data.messages.1.author_type', 'AGENT')
            ->assertJsonPath('data.messages.1.content', 'Le billet est à 25 euros, frais de service en plus, au paiement.')
            ->assertJsonPath('data.ask_contact', false);

        $call = DB::table('sms_assistant_calls')->where('organizer_id', $this->organizerId)->first();
        $this->assertSame('LIVE_CHAT', $call->trigger);
        $this->assertSame(1200, (int) $call->input_tokens);
        $this->assertSame(80, (int) $call->output_tokens);

        $this->chat('get', '/public/live-chat/messages', $sessionId)
            ->assertOk()
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_chat_without_contact_is_stored_but_not_to_handle(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer('Les portes ouvrent à 19 heures.'));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Ça ouvre à quelle heure ?'])->assertOk();

        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertSame('OPEN', $session->status);
        $this->assertFalse((bool) $session->needs_follow_up);
        $this->assertSame(1, (int) $session->visitor_message_count);
        $this->assertSame(2, DB::table('live_chat_messages')->where('live_chat_session_id', $session->id)->count());
        $this->assertSame(0, DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->count());
        Bus::assertNotDispatched(SendSmsInboundAlertJob::class);

        $this->organizerApi("/organizers/{$this->organizerId}/live-chats")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.visitor_phone', null)
            ->assertJsonPath('data.0.needs_follow_up', false);
    }

    public function test_contact_capture_creates_a_conversation_to_handle_with_the_transcript(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer(
            "Je transmets à l'organisateur. Laissez-moi votre numéro, il vous répond par SMS.",
            needsFollowUp: true,
            askContact: true,
            reason: 'Demande de remboursement',
        ));
        $this->visitorAlreadyWrote($sessionId, 2);

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Je veux être remboursé'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);

        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '0639 12 34 56'])
            ->assertOk()
            ->assertJsonPath('data.contact_given', true)
            ->assertJsonPath('data.messages.0.content', "Merci, c'est noté. L'organisateur vous répond par SMS au +262639123456.");

        $conversation = DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->first();
        $this->assertSame('+262639123456', $conversation->phone);
        $this->assertSame('NEEDS_HUMAN', $conversation->status);
        $this->assertSame('Chat web : Demande de remboursement', $conversation->needs_human_reason);
        $this->assertSame(1, (int) $conversation->unread_count);

        $messages = DB::table('sms_messages')->where('sms_conversation_id', $conversation->id)->orderBy('id')->get();
        $this->assertSame(['CONTACT', 'AGENT', 'AGENT'], $messages->pluck('author_type')->all());
        $this->assertSame(['INBOUND', 'OUTBOUND', 'OUTBOUND'], $messages->pluck('direction')->all());
        $this->assertSame(['WEB_CHAT'], $messages->pluck('channel')->unique()->values()->all());
        $this->assertSame('Je veux être remboursé', $messages[0]->content);

        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertSame('HANDED_OFF', $session->status);
        $this->assertSame((int) $conversation->id, (int) $session->sms_conversation_id);
        $this->assertTrue((bool) $session->needs_follow_up);
        Bus::assertDispatched(SendSmsInboundAlertJob::class);
    }

    public function test_contact_attaches_to_the_existing_conversation_of_the_phone(): void
    {
        $settingsId = $this->settings();
        $existingId = DB::table('sms_conversations')->insertGetId([
            'account_id' => DB::table('organizers')->where('id', $this->organizerId)->value('account_id'),
            'organizer_id' => $this->organizerId,
            'phone' => '+262639123456',
            'status' => 'OPEN',
            'unread_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $settingsId);
        $sessionId = $this->openChat();

        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '+262 639 12 34 56'])->assertOk();

        $this->assertSame(1, DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->count());
        $this->assertSame('NEEDS_HUMAN', DB::table('sms_conversations')->where('id', $existingId)->value('status'));
    }

    public function test_messages_after_the_hand_off_feed_the_sms_conversation(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '0639123456'])->assertOk();
        $this->claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], 'Coordonnées du visiteur déjà connues : oui'))
            ->andReturn($this->answer('Merci, il vous répond vite.', askContact: true));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Merci beaucoup'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', false);

        $conversationId = DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->value('id');
        $this->assertSame(
            ["Merci, c'est noté. L'organisateur vous répond par SMS au +262639123456.", 'Merci beaucoup', 'Merci, il vous répond vite.'],
            DB::table('sms_messages')->where('sms_conversation_id', $conversationId)->orderBy('id')->pluck('content')->all(),
        );
    }

    public function test_each_bubble_is_a_message_of_the_chat_and_of_the_sms_conversation(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '0639123456'])->assertOk();
        $this->claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], "Demande déjà transmise à l'organisateur : oui"))
            ->andReturn(new ClaudeStructuredAnswerDTO(
                data: [
                    'messages' => ['Heyy masta ! Le 10 décembre, c’est noté.', "L'organisateur te rappelle pour en parler."],
                    'needs_follow_up' => true,
                    'ask_contact' => false,
                    'reason' => 'Anniversaire privé',
                    'intent' => 'none',
                ],
                model: 'claude-haiku-5-5',
                inputTokens: 1200,
                outputTokens: 80,
            ));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => '10 décembre'])
            ->assertOk()
            ->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.1.author_type', 'AGENT')
            ->assertJsonPath('data.messages.1.content', 'Le 10 décembre, c’est noté.')
            ->assertJsonPath('data.messages.2.content', "L'organisateur te rappelle pour en parler.");

        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertSame(1, (int) $session->model_call_count);
        $this->assertSame('Anniversaire privé', $session->follow_up_reason);
        $this->assertSame(4, DB::table('live_chat_messages')->where('live_chat_session_id', $session->id)->count());
        $this->assertSame(1, DB::table('sms_assistant_calls')->where('organizer_id', $this->organizerId)->count());

        $this->assertSame(
            ['10 décembre', 'Le 10 décembre, c’est noté.', "L'organisateur te rappelle pour en parler."],
            DB::table('sms_messages')->where('sms_conversation_id', $session->sms_conversation_id)->orderBy('id')->pluck('content')->slice(1)->values()->all(),
        );

        $this->chat('get', '/public/live-chat/messages', $sessionId)
            ->assertOk()
            ->assertJsonCount(4, 'data.messages');
    }

    public function test_email_only_contact_keeps_the_chat_and_alerts_by_email(): void
    {
        $this->settings();
        $sessionId = $this->openChat();

        $this->chat('post', '/public/live-chat/contact', $sessionId, ['email' => 'Visiteur@Example.test'])
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', "Merci, c'est noté. L'organisateur vous répond par e-mail à visiteur@example.test.");

        $this->assertSame(0, DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->count());
        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertSame('visiteur@example.test', $session->visitor_email);
        $this->assertSame('HANDED_OFF', $session->status);
        Mail::assertQueued(LiveChatContactAlertMail::class);
    }

    public function test_invalid_contact_details_are_rejected(): void
    {
        $this->settings();
        $sessionId = $this->openChat();

        $this->chat('post', '/public/live-chat/contact', $sessionId, [])->assertUnprocessable();
        $this->chat('post', '/public/live-chat/contact', $sessionId, ['phone' => '12'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
        $this->chat('post', '/public/live-chat/contact', $sessionId, ['email' => 'pas-un-email'])->assertUnprocessable();
    }

    public function test_message_length_is_limited(): void
    {
        $this->settings();
        $sessionId = $this->openChat();

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => str_repeat('a', 801)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => '<b></b>'])->assertUnprocessable();
    }

    public function test_messages_per_session_are_limited(): void
    {
        config(['services.live_chat.max_messages_per_session' => 2]);
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->twice()->andReturn($this->answer('Oui.'));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Un'])->assertOk();
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Deux'])->assertOk();
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Trois'])
            ->assertStatus(429)
            ->assertJsonPath('limit', 'session')
            ->assertJsonPath('contact_form_fallback', true);
    }

    public function test_messages_per_ip_are_limited(): void
    {
        config(['services.live_chat.max_messages_per_ip_per_hour' => 1]);
        $this->settings();
        $first = $this->openChat();
        $second = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer('Oui.'));

        $this->chat('post', '/public/live-chat/messages', $first, ['message' => 'Un'])->assertOk();
        $this->chat('post', '/public/live-chat/messages', $second, ['message' => 'Deux'])
            ->assertStatus(429)
            ->assertJsonPath('limit', 'ip');
    }

    public function test_new_sessions_per_ip_are_limited(): void
    {
        config(['services.live_chat.max_sessions_per_ip_per_hour' => 2]);
        $this->settings();

        $this->openChat();
        $this->openChat();
        $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions")
            ->assertStatus(429)
            ->assertJsonPath('limit', 'new_sessions');
    }

    public function test_daily_budget_shows_the_contact_form_fallback(): void
    {
        config(['services.live_chat.daily_limit' => 1]);
        $this->settings();
        $sessionId = $this->openChat();
        DB::table('sms_assistant_calls')->insert([
            'organizer_id' => $this->organizerId,
            'trigger' => 'LIVE_CHAT',
            'outcome' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->claude->shouldNotReceive('askWithUsage');

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Bonjour ?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.contact_form_fallback', true);
    }

    public function test_live_chat_calls_do_not_use_the_sms_assistant_budget(): void
    {
        config(['services.anthropic.sms_assistant_daily_limit' => 1, 'services.live_chat.daily_limit' => 5]);
        $this->settings();
        DB::table('sms_assistant_calls')->insert([
            'organizer_id' => $this->organizerId,
            'trigger' => 'LIVE_CHAT',
            'outcome' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, DB::table('sms_assistant_calls')
            ->where('organizer_id', $this->organizerId)
            ->where('trigger', '!=', 'LIVE_CHAT')
            ->count());
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn($this->answer('Oui.'));

        $this->chat('post', '/public/live-chat/messages', $this->openChat(), ['message' => 'Un'])
            ->assertOk()
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_model_failure_gives_a_holding_reply_and_asks_for_contact(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->andThrow(new \HiEvents\Exceptions\EventAssistantFailedException('boom'));
        $this->visitorAlreadyWrote($sessionId, 2);

        $response = $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Bonjour'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);

        $this->assertStringContainsString("l'organisateur", $response->json('data.messages.1.content'));
        $this->assertTrue((bool) DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('needs_follow_up'));
    }

    public function test_sensitive_topic_always_needs_follow_up(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer("Je regarde avec l'organisateur."));
        $this->visitorAlreadyWrote($sessionId, 2);

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Je veux un remboursement de ma commande'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);
    }

    public function test_organizer_sees_a_chat_transcript(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer('Oui.'));
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Un'])->assertOk();
        $id = DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('id');

        $this->organizerApi("/organizers/{$this->organizerId}/live-chats/{$id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_contact_is_not_asked_before_the_third_message_of_the_visitor(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], 'Demande de coordonnées : pas encore'))
            ->twice()
            ->andReturn($this->answer('Je regarde.', askContact: true));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Bonjour'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', false);
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Vous êtes là ?'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', false);

        $this->claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], 'Message du visiteur n° 3')
                && str_contains($content[0]['text'], 'Demande de coordonnées : permise'))
            ->once()
            ->andReturn($this->answer('Laisse-moi ton numéro.', needsFollowUp: true, askContact: true, reason: 'Litige'));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Et alors ?'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);
        $this->assertNotNull(DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('contact_asked_at'));
    }

    public function test_a_hand_over_asks_for_contact_at_once_and_keeps_the_form(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')
            ->twice()
            ->andReturn($this->answer('Je transmets.', needsFollowUp: true, askContact: false, reason: 'Litige'));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Je suis pas content'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Toujours pas content'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);
    }

    public function test_contact_offered_by_the_visitor_can_be_taken_at_once(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer('Je transmets.', needsFollowUp: true, askContact: true, reason: 'Rappel'));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => "Rappelez-moi s'il vous plaît"])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', true);
    }

    public function test_contact_is_never_asked_again_after_a_refusal(): void
    {
        $this->settings();
        $sessionId = $this->openChat();
        $this->visitorAlreadyWrote($sessionId, 2);
        $this->claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content) => ! str_contains($content[0]['text'], 'refusée'))
            ->once()
            ->andReturn($this->answer('Laisse-moi ton numéro.', needsFollowUp: true, askContact: true, reason: 'Litige'));
        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Un souci'])->assertJsonPath('data.ask_contact', true);

        $this->claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content) => str_contains($content[0]['text'], 'Demande de coordonnées : refusée par le visiteur'))
            ->once()
            ->andReturn($this->answer('Pas de souci.', needsFollowUp: true, askContact: true, reason: 'Litige'));

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'non'])
            ->assertOk()
            ->assertJsonPath('data.ask_contact', false);
        $this->assertNotNull(DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('contact_declined_at'));
    }

    private function visitorAlreadyWrote(string $sessionId, int $count): void
    {
        DB::table('live_chat_sessions')->where('public_id', $sessionId)->update(['visitor_message_count' => $count]);
    }

    private function settings(bool $enabled = true, ?string $relayLabel = null, bool $withLine = true): int
    {
        $existing = DB::table('organizer_sms_settings')->where('organizer_id', $this->organizerId)->value('id');
        if ($existing !== null) {
            DB::table('organizer_sms_settings')->where('id', $existing)->update(['live_chat_enabled' => $enabled]);

            return (int) $existing;
        }

        return DB::table('organizer_sms_settings')->insertGetId([
            'account_id' => DB::table('organizers')->where('id', $this->organizerId)->value('account_id'),
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(48),
            'webhook_signing_key' => encrypt(Str::random(32)),
            'api_key' => $withLine ? Crypt::encryptString('test-api-key') : null,
            'from_number' => $withLine ? '+262639000001' : null,
            'assistant_instructions' => 'Dites bonjour',
            'live_chat_enabled' => $enabled,
            'live_chat_relay_label' => $relayLabel,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openChat(?int $eventId = null): string
    {
        return (string) $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions", ['event_id' => $eventId])
            ->assertCreated()
            ->json('data.session_id');
    }

    private function chat(string $method, string $uri, string $sessionId, array $data = []): TestResponse
    {
        return $this->json(strtoupper($method), $uri, $data, ['X-Chat-Session' => $sessionId]);
    }

    private function organizerApi(string $uri): TestResponse
    {
        $token = $this->loginAndGetToken($this->user, self::PASSWORD);

        return $this->getJson($uri, ['Authorization' => 'Bearer '.$token]);
    }

    private function answer(string $reply, bool $needsFollowUp = false, bool $askContact = false, ?string $reason = null): ClaudeStructuredAnswerDTO
    {
        return new ClaudeStructuredAnswerDTO(
            data: [
                'reply' => $reply,
                'needs_follow_up' => $needsFollowUp,
                'ask_contact' => $askContact,
                'reason' => $reason,
            ],
            model: 'claude-haiku-5-5',
            inputTokens: 1200,
            outputTokens: 80,
        );
    }
}
