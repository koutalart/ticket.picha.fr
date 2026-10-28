<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Resources\Event\EventSettingsResourcePublic;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\EventSettings\UpdateEventSettingsHandler;
use HiEvents\Services\Domain\Event\HomepageFooterSettingsSanitizer;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class HighlightedPromoCodeSettingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_defaults_to_null(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(
            1,
            2,
            (new OrganizerDomainObject)->setId(7)->setName('Innocent')->setEmail('innocent@example.com'),
        );

        $this->assertNull($dto->highlighted_promo_code);
        $this->assertNull((new EventSettingDomainObject)->getHighlightedPromoCode());
    }

    public function test_partial_update_passes_explicit_value_through(): void
    {
        $dto = $this->runPartialUpdate(existingValue: null, settings: ['highlighted_promo_code' => 'INNOCENTEVENT']);

        $this->assertSame('INNOCENTEVENT', $dto->highlighted_promo_code);
    }

    public function test_partial_update_can_clear_the_value(): void
    {
        $dto = $this->runPartialUpdate(existingValue: 'innocentevent', settings: ['highlighted_promo_code' => null]);

        $this->assertNull($dto->highlighted_promo_code);
    }

    public function test_partial_update_keeps_existing_value_when_omitted(): void
    {
        $dto = $this->runPartialUpdate(existingValue: 'innocentevent', settings: []);

        $this->assertSame('innocentevent', $dto->highlighted_promo_code);
    }

    public function test_full_update_stores_the_code_trimmed_and_lowercase(): void
    {
        $this->assertSame('innocentevent', $this->persistedCode(' INNOCENTEVENT '));
    }

    public function test_full_update_stores_null_for_an_empty_code(): void
    {
        $this->assertNull($this->persistedCode('   '));
    }

    public function test_resources_expose_the_setting(): void
    {
        $settings = (new EventSettingDomainObject)->setHighlightedPromoCode('innocentevent');

        $public = (new EventSettingsResourcePublic($settings))->toArray(Request::create('/'));
        $private = (new EventSettingsResource($settings))->toArray(Request::create('/'));

        $this->assertSame('innocentevent', $public['highlighted_promo_code']);
        $this->assertSame('innocentevent', $private['highlighted_promo_code']);
    }

    private function persistedCode(?string $code): ?string
    {
        Event::fake();
        $repository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject);

        $captured = 'not-called';
        $repository->shouldReceive('updateWhere')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured) {
                foreach ($args as $arg) {
                    if (is_array($arg) && array_key_exists('highlighted_promo_code', $arg)) {
                        $captured = $arg['highlighted_promo_code'];
                    }
                }

                return 1;
            });

        $purifier = Mockery::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(fn ($value) => $value);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        (new UpdateEventSettingsHandler(
            eventSettingsRepository: $repository,
            purifier: $purifier,
            databaseManager: $databaseManager,
            footerSettingsSanitizer: new HomepageFooterSettingsSanitizer($purifier),
        ))->handle(UpdateEventSettingsDTO::fromArray([
            'account_id' => 1,
            'event_id' => 1,
            'post_checkout_message' => null,
            'pre_checkout_message' => null,
            'email_footer_message' => null,
            'continue_button_text' => 'Continuer',
            'support_email' => 'innocent@example.com',
            'homepage_background_color' => '#ffffff',
            'homepage_primary_color' => '#000000',
            'homepage_primary_text_color' => '#000000',
            'homepage_secondary_color' => '#000000',
            'homepage_secondary_text_color' => '#ffffff',
            'homepage_body_background_color' => '#ffffff',
            'homepage_background_type' => 'COLOR',
            'require_attendee_details' => false,
            'attendee_details_collection_method' => 'PER_ORDER',
            'order_timeout_in_minutes' => 15,
            'website_url' => null,
            'maps_url' => null,
            'seo_title' => null,
            'seo_description' => null,
            'seo_keywords' => null,
            'highlighted_promo_code' => $code,
        ]));

        return $captured;
    }

    private function runPartialUpdate(?string $existingValue, array $settings): UpdateEventSettingsDTO
    {
        $existingSettings = (new EventSettingDomainObject)
            ->setHighlightedPromoCode($existingValue)
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
