<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\EventSettings\UpdateEventSettingsHandler;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ChooseCategoryFirstSettingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_defaults_to_false(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(
            1,
            2,
            (new OrganizerDomainObject)->setId(7)->setName('Salon')->setEmail('salon@example.com'),
        );

        $this->assertFalse($dto->choose_category_first);
        $this->assertFalse((new EventSettingDomainObject)->getChooseCategoryFirst());
    }

    public function test_partial_update_passes_explicit_value_through(): void
    {
        $dto = $this->runPartialUpdate(existingValue: false, settings: ['choose_category_first' => true]);

        $this->assertTrue($dto->choose_category_first);
    }

    public function test_partial_update_keeps_existing_value_when_omitted(): void
    {
        $dto = $this->runPartialUpdate(existingValue: true, settings: []);

        $this->assertTrue($dto->choose_category_first);
    }

    private function runPartialUpdate(bool $existingValue, array $settings): UpdateEventSettingsDTO
    {
        $existingSettings = (new EventSettingDomainObject)
            ->setChooseCategoryFirst($existingValue)
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
