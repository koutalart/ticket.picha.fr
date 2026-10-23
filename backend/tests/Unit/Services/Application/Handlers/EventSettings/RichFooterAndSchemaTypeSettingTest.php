<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

use HiEvents\DomainObjects\Enums\SchemaEventType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Resources\Event\EventSettingsResourcePublic;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\EventSettings\UpdateEventSettingsHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class RichFooterAndSchemaTypeSettingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_defaults_keep_the_current_footer_and_event_type(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(
            1,
            2,
            (new OrganizerDomainObject)->setId(7)->setName('Kultur')->setEmail('kultur@example.com'),
        );

        $this->assertFalse($dto->rich_footer_enabled);
        $this->assertNull($dto->homepage_footer_settings);
        $this->assertSame('Event', $dto->schema_event_type);
        $this->assertFalse((new EventSettingDomainObject)->getRichFooterEnabled());
        $this->assertSame('Event', (new EventSettingDomainObject)->getSchemaEventType());
    }

    public function test_partial_update_passes_new_values_through(): void
    {
        $footer = ['newsletter_title' => 'Restez informé', 'related_links' => []];

        $dto = $this->runPartialUpdate([
            'rich_footer_enabled' => true,
            'homepage_footer_settings' => $footer,
            'schema_event_type' => 'MusicEvent',
        ]);

        $this->assertTrue($dto->rich_footer_enabled);
        $this->assertSame($footer, $dto->homepage_footer_settings);
        $this->assertSame('MusicEvent', $dto->schema_event_type);
    }

    public function test_partial_update_keeps_existing_values_when_omitted(): void
    {
        $dto = $this->runPartialUpdate([]);

        $this->assertTrue($dto->rich_footer_enabled);
        $this->assertSame(['newsletter_title' => 'Existant'], $dto->homepage_footer_settings);
        $this->assertSame('Festival', $dto->schema_event_type);
    }

    public function test_request_rejects_unknown_event_types_and_unsafe_links(): void
    {
        $rules = (new UpdateEventSettingsRequest())->rules();
        $relevantRules = array_filter(
            $rules,
            fn(string $key) => str_starts_with($key, 'homepage_footer_settings') || $key === 'schema_event_type' || $key === 'rich_footer_enabled',
            ARRAY_FILTER_USE_KEY,
        );

        $valid = Validator::make([
            'rich_footer_enabled' => true,
            'schema_event_type' => 'MusicEvent',
            'homepage_footer_settings' => [
                'accessibility_url' => 'https://example.com/accessibilite',
                'event_links' => [['label' => 'Programme', 'url' => '/event/14/concert/programme']],
                'related_links' => [['title' => 'Mayotte La 1ère', 'url' => 'https://la1ere.francetvinfo.fr/mayotte/', 'image_url' => null]],
            ],
        ], $relevantRules);
        $this->assertFalse($valid->fails(), json_encode($valid->errors()->toArray()));

        $invalid = Validator::make([
            'schema_event_type' => 'Concert',
            'homepage_footer_settings' => [
                'privacy_policy_url' => 'javascript:alert(1)',
                'related_links' => array_fill(0, 4, ['title' => 'x', 'url' => 'https://example.com']),
            ],
        ], $relevantRules);

        $this->assertTrue($invalid->errors()->has('schema_event_type'));
        $this->assertTrue($invalid->errors()->has('homepage_footer_settings.privacy_policy_url'));
        $this->assertTrue($invalid->errors()->has('homepage_footer_settings.related_links'));
        $this->assertSame(SchemaEventType::valuesArray()[0], 'Event');
    }

    public function test_resources_expose_the_settings(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setRichFooterEnabled(true)
            ->setHomepageFooterSettings(['newsletter_title' => 'Restez informé'])
            ->setSchemaEventType('MusicEvent');

        $public = (new EventSettingsResourcePublic($settings))->toArray(Request::create('/'));
        $private = (new EventSettingsResource($settings))->toArray(Request::create('/'));

        foreach ([$public, $private] as $resource) {
            $this->assertTrue($resource['rich_footer_enabled']);
            $this->assertSame(['newsletter_title' => 'Restez informé'], $resource['homepage_footer_settings']);
            $this->assertSame('MusicEvent', $resource['schema_event_type']);
        }
    }

    private function runPartialUpdate(array $settings): UpdateEventSettingsDTO
    {
        $existingSettings = (new EventSettingDomainObject)
            ->setRichFooterEnabled(true)
            ->setHomepageFooterSettings(['newsletter_title' => 'Existant'])
            ->setSchemaEventType('Festival')
            ->setPaymentProviders([]);

        $repository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')
            ->with(['event_id' => 1])
            ->andReturn($existingSettings);

        $captured = null;
        $fullHandler = Mockery::mock(UpdateEventSettingsHandler::class);
        $fullHandler->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (UpdateEventSettingsDTO $dto) use (&$captured, $existingSettings) {
                $captured = $dto;

                return $existingSettings;
            });

        (new PartialUpdateEventSettingsHandler($fullHandler, $repository))->handle(new PartialUpdateEventSettingsDTO(
            account_id: 1,
            event_id: 1,
            settings: array_merge(['location_details' => []], $settings),
        ));

        return $captured;
    }
}
