<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

use HiEvents\DomainObjects\Enums\AgeRestrictionRule;
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

class AgeRestrictionSettingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_defaults_to_no_restriction_and_18(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(
            1,
            2,
            (new OrganizerDomainObject)->setId(7)->setName('Salon')->setEmail('salon@example.com'),
        );

        $this->assertSame(AgeRestrictionRule::NONE, $dto->age_restriction_rule);
        $this->assertSame(18, $dto->age_restriction_minimum_age);
    }

    public function test_partial_update_passes_explicit_values_through(): void
    {
        $dto = $this->runPartialUpdate(settings: [
            'age_restriction_rule' => 'ACCOMPANIED_MINORS',
            'age_restriction_minimum_age' => 16,
        ]);

        $this->assertSame(AgeRestrictionRule::ACCOMPANIED_MINORS, $dto->age_restriction_rule);
        $this->assertSame(16, $dto->age_restriction_minimum_age);
    }

    public function test_partial_update_keeps_existing_values_when_omitted(): void
    {
        $dto = $this->runPartialUpdate(settings: []);

        $this->assertSame(AgeRestrictionRule::MINIMUM_AGE, $dto->age_restriction_rule);
        $this->assertSame(21, $dto->age_restriction_minimum_age);
    }

    private function runPartialUpdate(array $settings): UpdateEventSettingsDTO
    {
        $existingSettings = (new EventSettingDomainObject)
            ->setAgeRestrictionRule(AgeRestrictionRule::MINIMUM_AGE->value)
            ->setAgeRestrictionMinimumAge(21)
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
