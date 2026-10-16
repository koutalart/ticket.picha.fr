<?php

namespace Tests\Unit\Services\Domain\Organizer;

use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Domain\Organizer\CreateDefaultOrganizerSettingsService;
use Mockery;
use Tests\TestCase;

class CreateDefaultOrganizerSettingsServiceTest extends TestCase
{
    public function test_new_organizers_collect_attendee_details_per_order_and_show_marketing_opt_in(): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static fn (array $attributes) => $attributes['organizer_id'] === 7
                && $attributes['default_attendee_details_collection_method'] === AttendeeDetailsCollectionMethod::PER_ORDER->name
                && $attributes['default_show_marketing_opt_in'] === true));

        (new CreateDefaultOrganizerSettingsService($repository))
            ->createOrganizerSettings((new OrganizerDomainObject)->setId(7));
    }

    public function test_new_organizers_pass_the_platform_fee_to_the_buyer(): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static fn (array $attributes) => $attributes['default_pass_platform_fee_to_buyer'] === true));

        (new CreateDefaultOrganizerSettingsService($repository))
            ->createOrganizerSettings((new OrganizerDomainObject)->setId(7));
    }

    public function test_new_events_pass_the_platform_fee_to_the_buyer_by_default(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(1, 2, (new OrganizerDomainObject)->setId(7)->setName('Konka Wawa')->setEmail('wawa@example.com'));

        $this->assertTrue($dto->pass_platform_fee_to_buyer);
    }
}
