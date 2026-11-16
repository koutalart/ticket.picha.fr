<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Exceptions\OrganizerTeamContactException;
use HiEvents\Exceptions\SmsInternalStaffPhoneException;
use HiEvents\Models\Organizer;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\SmsConversation;
use HiEvents\Models\SmsMessage;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * SMS exchanged with the PICHA staff (team contacts of the internal organizer « Anicka ») are never stored with a client.
 */
class SmsInternalStaffGuardTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const LINE = '+262639000000';

    private const STAFF = '+262639651126';

    private const CUSTOMER = '+262639111222';

    private int $clientId;

    private int $otherClientId;

    private int $anickaId;

    private OrganizerSmsSetting $line;

    private MockInterface $alerts;

    private MockInterface $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00', 'UTC'));
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);

        [$event] = $this->createEventWithProduct();
        $this->clientId = $event->organizer_id;
        $this->otherClientId = $this->organizer($event->account_id, 'Kulturm');
        $this->anickaId = $this->organizer($event->account_id, 'Anicka');

        $this->line = $this->settings($this->clientId, 'uk_line', self::LINE);
        $this->settings($this->otherClientId, null, null, $this->line->id);

        DB::table('organizer_team_contacts')->insert([
            'organizer_id' => $this->anickaId, 'phone' => self::STAFF, 'name' => 'Brayan', 'role' => 'ORGANIZER',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        config([
            'services.sms_file.enabled' => false,
            'services.platform_supervisor.phones' => '',
            'services.httpsms.internal_phones' => '',
            'services.httpsms.internal_organizer_id' => $this->anickaId,
        ]);

        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');
        $this->mock(HttpSmsClient::class)->shouldReceive('sendMessage')->andReturnUsing(fn () => 'prov-'.Str::random(8));
        $this->alerts = $this->mock(SmsAlertService::class, function (MockInterface $alerts) {
            $alerts->shouldReceive('markPhoneActive')->andReturnUsing(fn (OrganizerSmsSettingDomainObject $settings) => $settings)->byDefault();
            $alerts->shouldReceive('notifyInbound')->byDefault();
        });
        $this->assistant = $this->mock(SmsAssistantService::class, function (MockInterface $assistant) {
            $assistant->shouldReceive('queueDraftIfEligible')->byDefault();
        });
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sms_a_client_sends_to_the_staff_is_stored_with_the_internal_organizer(): void
    {
        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->otherClientId, phone: '0639 65 11 26', content: 'Tu peux scanner ?'));

        $stored = SmsMessage::query()->findOrFail($message->getId());
        $this->assertSame($this->anickaId, (int) $stored->organizer_id);
        $conversation = SmsConversation::query()->findOrFail($stored->sms_conversation_id);
        $this->assertSame($this->anickaId, (int) $conversation->organizer_id);
        $this->assertSame(self::STAFF, $conversation->phone);
        $this->assertTrue((bool) $conversation->is_team);
        $this->assertSame('Brayan', $conversation->contact_name);
        $this->assertSame(0, SmsConversation::query()->where('organizer_id', $this->otherClientId)->count());
        $this->assertSame(0, SmsMessage::query()->where('organizer_id', $this->otherClientId)->count());
    }

    public function test_staff_reply_on_the_shared_line_goes_to_the_internal_organizer_even_after_a_client_wrote(): void
    {
        $this->settings($this->anickaId, null, null, $this->line->id);
        app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->otherClientId, phone: self::STAFF, content: 'Ça va ?'));

        $this->receive('in-staff', self::STAFF, 'Oui, 120 entrées');

        $reply = SmsMessage::query()->where('provider_message_id', 'in-staff')->sole();
        $this->assertSame($this->anickaId, (int) $reply->organizer_id);
        $this->assertSame($this->anickaId, (int) SmsConversation::query()->findOrFail($reply->sms_conversation_id)->organizer_id);
        $this->assertSame(0, SmsMessage::query()->whereIn('organizer_id', [$this->clientId, $this->otherClientId])->count());
        $this->assistant->shouldNotHaveReceived('queueDraftIfEligible', [
            Mockery::on(fn (OrganizerSmsSettingDomainObject $settings) => $settings->getOrganizerId() !== $this->anickaId),
            Mockery::any(),
        ]);
        $this->alerts->shouldNotHaveReceived('notifyInbound', [
            Mockery::on(fn (OrganizerSmsSettingDomainObject $settings) => $settings->getOrganizerId() !== $this->anickaId),
            Mockery::any(),
        ]);
    }

    public function test_staff_sms_on_a_line_the_internal_organizer_is_not_on_is_never_stored_with_a_client(): void
    {
        DB::table('organizer_sms_settings')->where('organizer_id', $this->otherClientId)->delete();

        $this->receive('in-private', self::STAFF, 'Coucou');

        $reply = SmsMessage::query()->where('provider_message_id', 'in-private')->sole();
        $this->assertSame($this->anickaId, (int) $reply->organizer_id);
        $this->assertSame(0, SmsConversation::query()->where('organizer_id', $this->clientId)->where('phone', self::STAFF)->count());
        $this->alerts->shouldNotHaveReceived('notifyInbound');
        $this->assistant->shouldNotHaveReceived('queueDraftIfEligible');
    }

    public function test_customer_sms_stay_with_the_client(): void
    {
        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->otherClientId, phone: self::CUSTOMER, content: 'Votre billet'));
        $this->receive('in-customer', self::CUSTOMER, 'Merci');

        $this->assertSame($this->otherClientId, (int) SmsMessage::query()->findOrFail($message->getId())->organizer_id);
        $this->assertSame($this->otherClientId, (int) SmsMessage::query()->where('provider_message_id', 'in-customer')->sole()->organizer_id);
        $this->assertSame(0, SmsConversation::query()->where('organizer_id', $this->anickaId)->count());
    }

    public function test_the_internal_organizer_keeps_its_own_staff_conversations(): void
    {
        $this->settings($this->anickaId, null, null, $this->line->id);

        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->anickaId, phone: self::STAFF, content: 'Point du soir'));

        $this->assertSame($this->anickaId, (int) SmsMessage::query()->findOrFail($message->getId())->organizer_id);
        $this->assertSame(1, SmsConversation::query()->where('organizer_id', $this->anickaId)->where('phone', self::STAFF)->count());
    }

    public function test_without_an_internal_organizer_nothing_changes(): void
    {
        config(['services.httpsms.internal_organizer_id' => null]);

        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->otherClientId, phone: self::STAFF, content: 'Tu peux scanner ?'));
        $this->receive('in-unconfigured', self::STAFF, 'Oui');

        $this->assertSame($this->otherClientId, (int) SmsMessage::query()->findOrFail($message->getId())->organizer_id);
        $this->assertSame($this->otherClientId, (int) SmsMessage::query()->where('provider_message_id', 'in-unconfigured')->sole()->organizer_id);
        $this->assertSame(0, SmsConversation::query()->where('organizer_id', $this->anickaId)->count());

        $contact = app(OrganizerTeamContactService::class)->save($this->clientId, self::STAFF, 'Brayan', OrganizerTeamContactRole::COPY);
        $this->assertSame($this->clientId, $contact->getOrganizerId());
    }

    public function test_a_staff_number_cannot_join_the_team_of_a_client(): void
    {
        try {
            app(OrganizerTeamContactService::class)->save($this->clientId, '0639651126', 'Brayan', OrganizerTeamContactRole::COPY);
            $this->fail('A staff number joined the team of a client');
        } catch (SmsInternalStaffPhoneException $exception) {
            $this->assertInstanceOf(OrganizerTeamContactException::class, $exception);
        }

        $this->assertSame(0, DB::table('organizer_team_contacts')->where('organizer_id', $this->clientId)->count());
    }

    public function test_a_staff_number_saved_with_plus_33_still_cannot_join_a_client_team(): void
    {
        DB::table('organizer_team_contacts')->where('organizer_id', $this->anickaId)->update(['phone' => '+33639651126']);

        $this->expectException(SmsInternalStaffPhoneException::class);
        app(OrganizerTeamContactService::class)->save($this->clientId, self::STAFF, null, OrganizerTeamContactRole::ORGANIZER);
    }

    public function test_normal_numbers_join_a_client_team_and_staff_numbers_the_internal_team(): void
    {
        $service = app(OrganizerTeamContactService::class);

        $this->assertSame($this->clientId, $service->save($this->clientId, self::CUSTOMER, 'Responsable', OrganizerTeamContactRole::ORGANIZER)->getOrganizerId());
        $this->assertSame('Brayan Scan', $service->save($this->anickaId, self::STAFF, 'Brayan Scan', OrganizerTeamContactRole::ORGANIZER)->getName());
    }

    private function receive(string $messageId, string $contact, string $content): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: HandleHttpSmsWebhookHandler::EVENT_RECEIVED,
            payload: json_encode(['data' => [
                'message_id' => $messageId,
                'contact' => $contact,
                'owner' => self::LINE,
                'content' => $content,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ]]),
        ));
    }

    private function organizer(int $accountId, string $name): int
    {
        return Organizer::create([
            'account_id' => $accountId,
            'name' => $name,
            'email' => Str::lower($name).'@example.test',
            'currency' => 'EUR',
            'timezone' => 'UTC',
        ])->id;
    }

    private function settings(int $organizerId, ?string $apiKey, ?string $fromNumber, ?int $sharedLineId = null): OrganizerSmsSetting
    {
        return OrganizerSmsSetting::create([
            'account_id' => Organizer::find($organizerId)->account_id,
            'organizer_id' => $organizerId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => $apiKey,
            'from_number' => $fromNumber,
            'shared_line_settings_id' => $sharedLineId,
        ]);
    }
}
