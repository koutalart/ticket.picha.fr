<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\LiveChatStyle;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Http\Request\Sms\UpdateOrganizerSmsSettingsRequest;
use HiEvents\Models\Event;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\UpdateOrganizerSmsSettingsDTO;
use HiEvents\Services\Application\Handlers\Sms\UpdateOrganizerSmsSettingsHandler;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mockery;
use Spatie\LaravelData\Optional;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * « Messagerie » style of the live chat: the organizer picks the look of the chat and the assistant's photo.
 */
class LiveChatStyleTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private Event $event;

    private int $organizerId;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event] = $this->createEventWithProduct();
        $this->organizerId = (int) $this->event->organizer_id;

        config(['services.anthropic.api_key' => 'test-key']);
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $this->app->instance(ClaudeStructuredOutputClient::class, $claude);
    }

    public function test_config_is_classic_without_avatar_by_default(): void
    {
        $this->getJson("/public/organizers/{$this->organizerId}/live-chat")
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.style', 'CLASSIC')
            ->assertJsonPath('data.avatar_url', null);
    }

    public function test_new_settings_rows_default_to_the_classic_style(): void
    {
        $this->insertSettings();

        $this->assertSame('CLASSIC', DB::table('organizer_sms_settings')->where('organizer_id', $this->organizerId)->value('live_chat_style'));
    }

    public function test_config_gives_the_messaging_style_and_the_avatar(): void
    {
        $this->insertSettings(['live_chat_style' => LiveChatStyle::MESSAGING->value]);
        DB::table('images')->insert([
            'account_id' => $this->event->account_id,
            'entity_id' => $this->organizerId,
            'entity_type' => OrganizerDomainObject::class,
            'type' => ImageType::ORGANIZER_CHAT_AVATAR->name,
            'filename' => 'anicka.webp',
            'disk' => 'public',
            'path' => 'organizers/anicka.webp',
            'size' => 1000,
            'mime_type' => 'image/webp',
        ]);

        $response = $this->getJson("/public/organizers/{$this->organizerId}/live-chat")
            ->assertOk()
            ->assertJsonPath('data.style', 'MESSAGING');

        $this->assertStringEndsWith('organizers/anicka.webp', (string) $response->json('data.avatar_url'));
    }

    public function test_avatar_of_another_organizer_is_not_used(): void
    {
        $this->insertSettings(['live_chat_style' => LiveChatStyle::MESSAGING->value]);
        DB::table('images')->insert([
            'account_id' => $this->event->account_id,
            'entity_id' => $this->organizerId + 100000,
            'entity_type' => OrganizerDomainObject::class,
            'type' => ImageType::ORGANIZER_CHAT_AVATAR->name,
            'path' => 'organizers/other.webp',
        ]);

        $this->getJson("/public/organizers/{$this->organizerId}/live-chat")
            ->assertJsonPath('data.avatar_url', null);
    }

    public function test_handler_saves_the_chosen_style(): void
    {
        $settings = (new OrganizerSmsSettingDomainObject)->setId(7)->setOrganizerId(4)->setAccountId(1);
        $updated = (new OrganizerSmsSettingDomainObject)->setId(7)->setOrganizerId(4)->setAccountId(1)
            ->setLiveChatStyle(LiveChatStyle::MESSAGING->value);

        $settingsService = Mockery::mock(SmsSettingsService::class);
        $settingsService->shouldReceive('getOrCreate')->with(1, 4)->andReturn($settings);
        $settingsService->shouldReceive('getWebhookUrl')->andReturn('');
        $settingsService->shouldReceive('findSharedLine')->andReturn(null);
        $settingsService->shouldReceive('defaultRemindersEnabled')->andReturn(true);

        $repository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $repository->shouldReceive('updateFromArray')
            ->once()
            ->with(7, [OrganizerSmsSettingDomainObjectAbstract::LIVE_CHAT_STYLE => 'MESSAGING'])
            ->andReturn($updated);

        $assistant = Mockery::mock(SmsAssistantService::class);
        $assistant->shouldReceive('isAvailable')->andReturn(true);

        $handler = new UpdateOrganizerSmsSettingsHandler(
            $settingsService,
            $repository,
            $assistant,
            Mockery::mock(HtmlPurifierService::class),
        );

        $result = $handler->handle(new UpdateOrganizerSmsSettingsDTO(
            accountId: 1,
            organizerId: 4,
            apiKey: new Optional,
            fromNumber: new Optional,
            assistantEnabled: new Optional,
            assistantInstructions: new Optional,
            liveChatStyle: LiveChatStyle::MESSAGING,
        ));

        $this->assertSame('MESSAGING', $result->settings->getLiveChatStyle());
    }

    public function test_request_only_accepts_known_styles(): void
    {
        $rules = (new UpdateOrganizerSmsSettingsRequest)->rules();

        $this->assertTrue(Validator::make(['live_chat_style' => 'MESSAGING'], $rules)->passes());
        $this->assertTrue(Validator::make(['live_chat_style' => 'CLASSIC'], $rules)->passes());
        $this->assertFalse(Validator::make(['live_chat_style' => 'WHATSAPP'], $rules)->passes());
    }

    public function test_chat_avatar_is_an_organizer_image(): void
    {
        $this->assertSame(OrganizerDomainObject::class, ImageType::ORGANIZER_CHAT_AVATAR->getEntityType());
        $this->assertContains(ImageType::ORGANIZER_CHAT_AVATAR, ImageType::organizerImageTypes());
    }

    private function insertSettings(array $attributes = []): void
    {
        DB::table('organizer_sms_settings')->where('organizer_id', $this->organizerId)->delete();
        DB::table('organizer_sms_settings')->insert(array_merge([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(48),
            'webhook_signing_key' => encrypt(Str::random(32)),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
