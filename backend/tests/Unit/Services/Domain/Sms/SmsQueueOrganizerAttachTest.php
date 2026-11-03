<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Models\Organizer;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\SmsConversation;
use HiEvents\Models\SmsMessage;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsFileSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Organizer "Innocent" owns the shared line; the central queue sends a first SMS from it for "Decoremoi",
 * an organizer that has no SMS settings yet.
 */
class SmsQueueOrganizerAttachTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const LINE_NUMBER = '+262639250660';

    private const CUSTOMER = '+262639298806';

    private const QUEUE_ID = 4321;

    private int $innocentId;

    private int $decoremoiId;

    private OrganizerSmsSetting $line;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);

        [$event] = $this->createEventWithProduct();
        $this->innocentId = $event->organizer_id;
        $this->decoremoiId = $this->organizer($event->account_id, 'Decoremoi');

        $this->line = OrganizerSmsSetting::create([
            'account_id' => $event->account_id,
            'organizer_id' => $this->innocentId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'uk_line',
            'from_number' => self::LINE_NUMBER,
        ]);

        $this->mock(SmsFileSyncService::class)->shouldReceive('findQueueOrganizerId')
            ->with(self::QUEUE_ID)
            ->andReturnUsing(fn () => $this->decoremoiId);
        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');
        $this->mock(SmsAlertService::class, function (MockInterface $alerts) {
            $alerts->shouldReceive('markPhoneActive')->andReturnUsing(fn (OrganizerSmsSettingDomainObject $settings) => $settings);
            $alerts->shouldReceive('notifyInbound');
        });
        $this->mock(SmsAssistantService::class)->shouldReceive('queueDraftIfEligible');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_queue_sms_for_an_organizer_without_settings_attaches_it_and_its_customer_answers_it(): void
    {
        $this->sent('prov-1', 'message.phone.sent');
        $this->sent('prov-1', 'message.phone.delivered');

        $settings = OrganizerSmsSetting::query()->where('organizer_id', $this->decoremoiId)->sole();
        $this->assertSame($this->line->id, $settings->shared_line_settings_id);
        $this->assertFalse((bool) $settings->assistant_auto_send);
        $this->assertFalse((bool) $settings->assistant_holding_reply_enabled);

        $conversation = SmsConversation::query()->where('phone', self::CUSTOMER)->sole();
        $this->assertSame($this->decoremoiId, $conversation->organizer_id);
        $outbound = SmsMessage::query()->where('provider_message_id', 'prov-1')->sole();
        $this->assertSame($this->decoremoiId, $outbound->organizer_id);
        $this->assertSame('DELIVERED', $outbound->status);

        Carbon::setTestNow(Carbon::now('UTC')->addHour());
        $this->received('in-1', 'Bonjour, merci !');

        $answer = SmsMessage::query()->where('provider_message_id', 'in-1')->sole();
        $this->assertSame($this->decoremoiId, $answer->organizer_id);
        $this->assertSame($conversation->id, $answer->sms_conversation_id);
        $this->assertSame(0, SmsConversation::query()->where('organizer_id', $this->innocentId)->count());
    }

    public function test_queue_sms_for_an_organizer_with_its_own_line_stays_with_the_line_owner(): void
    {
        OrganizerSmsSetting::create([
            'account_id' => Organizer::find($this->decoremoiId)->account_id,
            'organizer_id' => $this->decoremoiId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'uk_own',
            'from_number' => '+262639111111',
        ]);

        $this->sent('prov-2', 'message.phone.sent');

        $this->assertNull(OrganizerSmsSetting::query()->where('organizer_id', $this->decoremoiId)->sole()->shared_line_settings_id);
        $this->assertSame($this->innocentId, SmsConversation::query()->where('phone', self::CUSTOMER)->sole()->organizer_id);
    }

    private function sent(string $providerMessageId, string $type): void
    {
        $this->webhook($type, [
            'id' => $providerMessageId,
            'contact' => self::CUSTOMER,
            'owner' => self::LINE_NUMBER,
            'content' => 'Bonjour Chaima, je suis Anicka.',
            'request_id' => 'file-'.self::QUEUE_ID,
            'timestamp' => Carbon::now('UTC')->toIso8601String(),
        ]);
    }

    private function received(string $messageId, string $content): void
    {
        $this->webhook(HandleHttpSmsWebhookHandler::EVENT_RECEIVED, [
            'message_id' => $messageId,
            'contact' => self::CUSTOMER,
            'owner' => self::LINE_NUMBER,
            'content' => $content,
            'timestamp' => Carbon::now('UTC')->toIso8601String(),
        ]);
    }

    private function webhook(string $type, array $data): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: $type,
            payload: json_encode(['type' => $type, 'data' => $data]),
        ));
    }

    private function organizer(int $accountId, string $name): int
    {
        return Organizer::create([
            'account_id' => $accountId,
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'currency' => 'EUR',
            'timezone' => 'UTC',
        ])->id;
    }
}
