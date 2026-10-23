<?php

namespace Tests\Unit\Services\Domain\Sms\Watchdog;

use HiEvents\DomainObjects\Enums\SmsWatchdogAnomalyType;
use HiEvents\Mail\Sms\SmsWatchdogAlertMail;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\SmsConversation;
use HiEvents\Models\SmsMessage;
use HiEvents\Models\SmsOptOut;
use HiEvents\Models\SmsRegistrationConfirmation;
use HiEvents\Models\SmsWatchdogAlert;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogAnomalyDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogDetectionDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogRunResultDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Domain\Sms\Watchdog\SmsWatchdogAlertService;
use HiEvents\Services\Domain\Sms\Watchdog\SmsWatchdogDetector;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SmsWatchdogTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PHONE = '+262639250206';

    private const NOW = '2026-10-23 12:00:00';

    private Event $event;

    private SmsConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config([
            'services.sms_watchdog.alert_email' => 'owner@picha.test',
            'services.sms_watchdog.alert_phone' => null,
        ]);

        DB::table('organizer_sms_settings')->update(['assistant_auto_send' => false]);

        [$this->event] = $this->createEventWithProduct();
        $this->conversation = $this->conversationFor($this->event->organizer_id, self::PHONE);
        $this->settingsFor($this->event->organizer_id, autoSend: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_question_without_reply_for_ten_minutes_is_reported(): void
    {
        $question = $this->inbound('Il reste des places pour samedi ?', minutesAgo: 11);

        $anomalies = $this->anomalies(SmsWatchdogAnomalyType::UNANSWERED_INBOUND);

        $this->assertCount(1, $anomalies);
        $this->assertSame('conversation:'.$this->conversation->id.':inbound:'.$question->id, $anomalies[0]->key);
        $this->assertSame($this->conversation->id, $anomalies[0]->conversationId);
        $this->assertSame(self::PHONE, $anomalies[0]->phone);
    }

    public function test_recent_answered_acknowledged_closed_or_opted_out_messages_are_not_reported(): void
    {
        $this->inbound('Vous ouvrez à quelle heure ?', minutesAgo: 5);

        $answered = $this->conversationFor($this->event->organizer_id, '+262639000001');
        $this->inbound('Il y a un parking ?', minutesAgo: 40, conversation: $answered);
        $this->outbound('Oui, devant la salle.', minutesAgo: 35, conversation: $answered);

        $acknowledged = $this->conversationFor($this->event->organizer_id, '+262639000002');
        $this->inbound('Merci beaucoup 👍', minutesAgo: 40, conversation: $acknowledged);

        $closed = $this->conversationFor($this->event->organizer_id, '+262639000003', status: 'CLOSED');
        $this->inbound('Et pour les enfants ?', minutesAgo: 40, conversation: $closed);

        $optedOut = $this->conversationFor($this->event->organizer_id, '+262639000004');
        $this->inbound('Encore une question ?', minutesAgo: 40, conversation: $optedOut);
        SmsOptOut::create(['organizer_id' => $optedOut->organizer_id, 'phone' => $optedOut->phone, 'source' => 'KEYWORD']);

        $this->assertSame([], $this->anomalies(SmsWatchdogAnomalyType::UNANSWERED_INBOUND));
    }

    public function test_a_failed_reply_does_not_count_as_an_answer(): void
    {
        $this->inbound('Je peux payer sur place ?', minutesAgo: 30);
        $this->outbound('Oui bien sûr.', minutesAgo: 28, status: 'FAILED');

        $this->assertCount(1, $this->anomalies(SmsWatchdogAnomalyType::UNANSWERED_INBOUND));
    }

    public function test_an_automatic_answer_followed_by_a_team_reply_is_a_duplicate(): void
    {
        $question = $this->inbound('C’est où exactement ?', minutesAgo: 30);
        $this->outbound('À la salle polyvalente de Mamoudzou.', minutesAgo: 29, author: 'AGENT', replyTo: $question, replyType: 'ANSWER');
        $this->outbound('Salle polyvalente, Mamoudzou', minutesAgo: 27);

        $anomalies = $this->anomalies(SmsWatchdogAnomalyType::DUPLICATE_REPLY);

        $this->assertCount(1, $anomalies);
        $this->assertSame('inbound:'.$question->id, $anomalies[0]->key);
        $this->assertSame(2, $anomalies[0]->count);
    }

    public function test_the_same_reply_sent_twice_is_a_duplicate(): void
    {
        $this->inbound('Le concert finit à quelle heure ?', minutesAgo: 30);
        $this->outbound('Vers 23h.', minutesAgo: 29);
        $this->outbound('Vers 23h. ', minutesAgo: 28);

        $this->assertCount(1, $this->anomalies(SmsWatchdogAnomalyType::DUPLICATE_REPLY));
    }

    public function test_a_holding_reply_then_the_team_answer_or_two_different_team_messages_are_not_duplicates(): void
    {
        $question = $this->inbound('Je peux venir avec mon fils ?', minutesAgo: 30);
        $this->outbound('Nous revenons vers vous très vite.', minutesAgo: 29, author: 'AGENT', replyTo: $question, replyType: 'HOLDING');
        $this->outbound('Oui, il est le bienvenu.', minutesAgo: 25);

        $other = $this->conversationFor($this->event->organizer_id, '+262639000005');
        $this->inbound('Bonjour, des infos ?', minutesAgo: 30, conversation: $other);
        $this->outbound('Bonjour !', minutesAgo: 29, conversation: $other);
        $this->outbound('Le concert commence à 20h.', minutesAgo: 28, conversation: $other);

        $this->assertSame([], $this->anomalies(SmsWatchdogAnomalyType::DUPLICATE_REPLY));
    }

    public function test_replies_far_from_the_inbound_sms_are_not_duplicates(): void
    {
        $this->inbound('Il reste des places ?', minutesAgo: 60);
        $this->outbound('Oui.', minutesAgo: 58);
        $this->outbound('Oui.', minutesAgo: 30);

        $this->assertSame([], $this->anomalies(SmsWatchdogAnomalyType::DUPLICATE_REPLY));
    }

    public function test_a_second_registration_confirmation_to_the_same_phone_is_reported(): void
    {
        [$first, $second] = $this->twoAttendees();

        $earlier = $this->confirmation($first, minutesAgo: 60);
        $earlier->update(['phone_released_at' => Carbon::now()->subMinutes(40)]);
        $later = $this->confirmation($second, minutesAgo: 50);
        $earlier->update(['phone_released_at' => Carbon::now()->subMinutes(10)]);

        $anomalies = $this->anomalies(SmsWatchdogAnomalyType::DUPLICATE_REGISTRATION_CONFIRMATION);

        $this->assertCount(1, $anomalies);
        $this->assertSame('confirmation:'.$later->id, $anomalies[0]->key);
        $this->assertSame($this->event->id, $anomalies[0]->eventId);
    }

    public function test_a_confirmation_after_the_phone_was_released_is_not_reported(): void
    {
        [$first, $second] = $this->twoAttendees();

        $earlier = $this->confirmation($first, minutesAgo: 60);
        $earlier->update(['phone_released_at' => Carbon::now()->subMinutes(55)]);
        $this->confirmation($second, minutesAgo: 50);

        $this->assertSame([], $this->anomalies(SmsWatchdogAnomalyType::DUPLICATE_REGISTRATION_CONFIRMATION));
    }

    public function test_stuck_pending_and_failed_sms_of_the_last_day_are_reported(): void
    {
        $stuck = $this->outbound('Votre billet', minutesAgo: 20, status: 'PENDING');
        $this->outbound('Votre billet', minutesAgo: 5, status: 'PENDING');
        $failed = $this->outbound('Rappel', minutesAgo: 120, status: 'FAILED', error: 'phone offline');
        $this->outbound('Ancien', minutesAgo: 25 * 60, status: 'FAILED');
        $this->outbound('Parti', minutesAgo: 30, status: 'SENT');

        $stuckAnomalies = $this->anomalies(SmsWatchdogAnomalyType::STUCK_PENDING);
        $failedAnomalies = $this->anomalies(SmsWatchdogAnomalyType::FAILED_OUTBOUND);

        $this->assertSame(['message:'.$stuck->id], array_map(fn ($a) => $a->key, $stuckAnomalies));
        $this->assertSame(['message:'.$failed->id], array_map(fn ($a) => $a->key, $failedAnomalies));
        $this->assertSame('phone offline', $failedAnomalies[0]->excerpt);
    }

    public function test_organizers_without_automatic_sending_are_not_watched(): void
    {
        DB::table('organizer_sms_settings')->where('organizer_id', $this->event->organizer_id)->update(['assistant_auto_send' => false]);
        $this->inbound('Il reste des places ?', minutesAgo: 30);

        $this->assertSame([], $this->anomalies());
    }

    public function test_an_anomaly_is_alerted_once_and_again_only_after_it_came_back(): void
    {
        Mail::fake();
        $this->outbound('Votre billet', minutesAgo: 20, status: 'PENDING');

        $this->runAlerts();
        $this->runAlerts();
        Mail::assertQueuedCount(1);

        $alert = SmsWatchdogAlert::where('anomaly_type', 'STUCK_PENDING')->sole();
        $this->assertNotNull($alert->alerted_at);
        $this->assertNull($alert->resolved_at);

        SmsMessage::query()->update(['status' => 'SENT']);
        $this->assertSame(1, $this->runAlerts()->resolved);
        $this->assertNotNull($alert->fresh()->resolved_at);

        SmsMessage::query()->update(['status' => 'PENDING']);
        $this->runAlerts();

        Mail::assertQueuedCount(2);
        $this->assertNull($alert->fresh()->resolved_at);
        $this->assertSame(1, SmsWatchdogAlert::count());
    }

    public function test_the_alert_mail_goes_to_the_platform_owner_with_every_new_anomaly(): void
    {
        Mail::fake();
        $this->inbound('Il reste des places ?', minutesAgo: 30, conversation: $this->conversationFor($this->event->organizer_id, '+262639000006'));
        $this->outbound('Votre billet', minutesAgo: 20, status: 'PENDING');

        $result = $this->runAlerts();

        $this->assertSame(2, $result->alerted);
        Mail::assertQueued(SmsWatchdogAlertMail::class, fn (SmsWatchdogAlertMail $mail) => $mail->hasTo('owner@picha.test'));
        Mail::assertQueued(SmsWatchdogAlertMail::class, fn (SmsWatchdogAlertMail $mail) => ! $mail->hasTo('organizer@example.test'));
    }

    public function test_without_a_recipient_nothing_is_marked_alerted_and_the_next_run_catches_up(): void
    {
        Mail::fake();
        config(['services.sms_watchdog.alert_email' => null, 'app.platform_support_email' => 'support@example.com']);
        $this->outbound('Votre billet', minutesAgo: 20, status: 'PENDING');

        $this->assertTrue($this->runAlerts()->recipientMissing);
        Mail::assertNothingQueued();
        $this->assertNull(SmsWatchdogAlert::sole()->alerted_at);

        config(['app.platform_support_email' => 'jo@picha.test']);
        $this->runAlerts();

        Mail::assertQueued(SmsWatchdogAlertMail::class, fn (SmsWatchdogAlertMail $mail) => $mail->hasTo('jo@picha.test'));
        $this->assertNotNull(SmsWatchdogAlert::sole()->alerted_at);
    }

    public function test_a_check_cut_by_its_limit_resolves_nothing(): void
    {
        Mail::fake();
        $service = $this->app->make(SmsWatchdogAlertService::class);
        $anomaly = new SmsWatchdogAnomalyDTO(SmsWatchdogAnomalyType::FAILED_OUTBOUND, 'message:999999', $this->event->organizer_id, 'Test');

        $service->process(new SmsWatchdogDetectionDTO([$anomaly], [$this->event->organizer_id => []]));
        $service->process(new SmsWatchdogDetectionDTO([], [$this->event->organizer_id => []]));

        $this->assertNull(SmsWatchdogAlert::sole()->resolved_at);
    }

    public function test_dry_run_prints_anomalies_without_recording_or_alerting(): void
    {
        Mail::fake();
        $this->outbound('Votre billet', minutesAgo: 20, status: 'PENDING');

        $exitCode = Artisan::call('sms:watchdog', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('STUCK_PENDING', $output);
        $this->assertStringContainsString('********0206', $output);
        $this->assertStringNotContainsString(self::PHONE, $output);
        $this->assertStringContainsString('Dry run: 1 anomalies', $output);
        Mail::assertNothingQueued();
        $this->assertSame(0, SmsWatchdogAlert::count());
    }

    public function test_the_watchdog_never_sends_an_sms_even_with_an_alert_phone(): void
    {
        Mail::fake();
        Queue::fake();
        config(['services.sms_watchdog.alert_phone' => '+262639999999']);
        $this->instance(HttpSmsClient::class, Mockery::mock(HttpSmsClient::class)->shouldNotReceive('sendMessage')->getMock());
        $this->instance(SmsSendService::class, Mockery::mock(SmsSendService::class)->shouldNotReceive('send')->getMock());

        $this->inbound('Il reste des places ?', minutesAgo: 30);
        $this->outbound('Votre billet', minutesAgo: 20, status: 'PENDING');
        $messagesBefore = SmsMessage::count();
        $conversationBefore = $this->conversation->fresh()->toArray();

        $this->artisan('sms:watchdog')->assertSuccessful();

        Mail::assertQueuedCount(1);
        Queue::assertNothingPushed();
        $this->assertSame($messagesBefore, SmsMessage::count());
        $this->assertSame($conversationBefore, $this->conversation->fresh()->toArray());
    }

    /**
     * @return array<int, SmsWatchdogAnomalyDTO>
     */
    private function anomalies(?SmsWatchdogAnomalyType $type = null): array
    {
        $anomalies = array_filter(
            $this->app->make(SmsWatchdogDetector::class)->detect()->anomalies,
            fn (SmsWatchdogAnomalyDTO $anomaly) => $anomaly->organizerId === $this->event->organizer_id
                && ($type === null || $anomaly->type === $type),
        );

        return array_values($anomalies);
    }

    private function runAlerts(): SmsWatchdogRunResultDTO
    {
        return $this->app->make(SmsWatchdogAlertService::class)->process($this->app->make(SmsWatchdogDetector::class)->detect());
    }

    private function settingsFor(int $organizerId, bool $autoSend): void
    {
        OrganizerSmsSetting::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $organizerId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'assistant_auto_send' => $autoSend,
        ]);
    }

    private function conversationFor(int $organizerId, string $phone, string $status = 'OPEN'): SmsConversation
    {
        return SmsConversation::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $organizerId,
            'phone' => $phone,
            'status' => $status,
        ]);
    }

    private function inbound(string $content, int $minutesAgo, ?SmsConversation $conversation = null): SmsMessage
    {
        return $this->message($conversation ?? $this->conversation, [
            'direction' => 'INBOUND',
            'author_type' => 'CONTACT',
            'content' => $content,
            'status' => 'RECEIVED',
        ], $minutesAgo);
    }

    private function outbound(
        string $content,
        int $minutesAgo,
        ?SmsConversation $conversation = null,
        string $status = 'SENT',
        string $author = 'HUMAN',
        ?SmsMessage $replyTo = null,
        ?string $replyType = null,
        ?string $error = null,
    ): SmsMessage {
        return $this->message($conversation ?? $this->conversation, [
            'direction' => 'OUTBOUND',
            'author_type' => $author,
            'content' => $content,
            'status' => $status,
            'auto_reply_to_message_id' => $replyTo?->id,
            'auto_reply_type' => $replyType,
            'error_message' => $error,
        ], $minutesAgo);
    }

    private function message(SmsConversation $conversation, array $attributes, int $minutesAgo): SmsMessage
    {
        $at = Carbon::now()->subMinutes($minutesAgo);

        return SmsMessage::create([
            'sms_conversation_id' => $conversation->id,
            'organizer_id' => $conversation->organizer_id,
            ...$attributes,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function twoAttendees(): array
    {
        $product = Product::where('event_id', $this->event->id)->firstOrFail();
        $price = ProductPrice::where('product_id', $product->id)->firstOrFail();

        return [
            $this->createAttendeeViaHandler($this->event->id, $product->id, $price->id),
            $this->createAttendeeViaHandler($this->event->id, $product->id, $price->id),
        ];
    }

    private function confirmation(Attendee $attendee, int $minutesAgo): SmsRegistrationConfirmation
    {
        $at = Carbon::now()->subMinutes($minutesAgo);

        return SmsRegistrationConfirmation::create([
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'order_id' => $attendee->order_id,
            'attendee_id' => $attendee->id,
            'phone' => self::PHONE,
            'status' => 'SENT',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
