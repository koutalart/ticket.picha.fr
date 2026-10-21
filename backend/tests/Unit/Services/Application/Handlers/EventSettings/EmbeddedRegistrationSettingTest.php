<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

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
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class EmbeddedRegistrationSettingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_defaults_to_false(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(
            1,
            2,
            (new OrganizerDomainObject)->setId(7)->setName('Kultur')->setEmail('kultur@example.com'),
        );

        $this->assertFalse($dto->embedded_registration_enabled);
        $this->assertFalse((new EventSettingDomainObject)->getEmbeddedRegistrationEnabled());
    }

    public function test_partial_update_passes_explicit_value_through(): void
    {
        $dto = $this->runPartialUpdate(existingValue: false, settings: ['embedded_registration_enabled' => true]);

        $this->assertTrue($dto->embedded_registration_enabled);
    }

    public function test_partial_update_can_disable_the_setting(): void
    {
        $dto = $this->runPartialUpdate(existingValue: true, settings: ['embedded_registration_enabled' => false]);

        $this->assertFalse($dto->embedded_registration_enabled);
    }

    public function test_partial_update_keeps_existing_value_when_omitted(): void
    {
        $dto = $this->runPartialUpdate(existingValue: true, settings: []);

        $this->assertTrue($dto->embedded_registration_enabled);
    }

    public function test_request_validates_the_setting_as_boolean(): void
    {
        $rules = (new UpdateEventSettingsRequest())->rules();

        $this->assertSame(['boolean'], $rules['embedded_registration_enabled']);
    }

    public function test_resources_expose_the_setting(): void
    {
        $settings = (new EventSettingDomainObject)->setEmbeddedRegistrationEnabled(true);

        $public = (new EventSettingsResourcePublic($settings))->toArray(Request::create('/'));
        $private = (new EventSettingsResource($settings))->toArray(Request::create('/'));

        $this->assertTrue($public['embedded_registration_enabled']);
        $this->assertTrue($private['embedded_registration_enabled']);
    }

    private function runPartialUpdate(bool $existingValue, array $settings): UpdateEventSettingsDTO
    {
        $existingSettings = (new EventSettingDomainObject)
            ->setEmbeddedRegistrationEnabled($existingValue)
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
