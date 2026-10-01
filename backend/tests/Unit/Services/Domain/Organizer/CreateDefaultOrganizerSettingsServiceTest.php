<?php

namespace Tests\Unit\Services\Domain\Organizer;

use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\Organizer\CreateDefaultOrganizerSettingsService;
use Mockery;
use Tests\TestCase;

class CreateDefaultOrganizerSettingsServiceTest extends TestCase
{
    public function testNewOrganizersCollectAttendeeDetailsPerOrderAndShowMarketingOptIn(): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static fn(array $attributes) => $attributes['organizer_id'] === 7
                && $attributes['default_attendee_details_collection_method'] === AttendeeDetailsCollectionMethod::PER_ORDER->name
                && $attributes['default_show_marketing_opt_in'] === true));

        (new CreateDefaultOrganizerSettingsService($repository))
            ->createOrganizerSettings((new OrganizerDomainObject())->setId(7));
    }
}
