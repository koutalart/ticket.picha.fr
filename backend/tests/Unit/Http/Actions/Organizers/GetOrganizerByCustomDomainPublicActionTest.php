<?php

namespace Tests\Unit\Http\Actions\Organizers;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\Organizers\GetOrganizerByCustomDomainPublicAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Organizer\CustomDomainCurrentEventService;
use Mockery as m;
use Tests\TestCase;

class GetOrganizerByCustomDomainPublicActionTest extends TestCase
{
    public function test_the_custom_domain_lookup_carries_the_tone_of_the_organizer(): void
    {
        self::assertSame('tu', $this->lookup(CustomerAddressForm::TU)['customer_address_form']);
        self::assertSame('vous', $this->lookup(CustomerAddressForm::VOUS)['customer_address_form']);
    }

    private function lookup(CustomerAddressForm $addressForm): array
    {
        $organizer = (new OrganizerDomainObject)->setId(4)->setName('Innocent Event')->setStatus('LIVE')->setCustomDomain('innocent976.yt');

        $organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $organizerRepository->shouldReceive('findFirstWhere')->with(['custom_domain' => 'innocent976.yt'])->andReturn($organizer);

        $currentEventService = m::mock(CustomDomainCurrentEventService::class);
        $currentEventService->shouldReceive('findEventToRedirectTo')->andReturnNull();

        $customerLocaleResolver = m::mock(CustomerLocaleResolver::class);
        $customerLocaleResolver->shouldReceive('addressFormForOrganizerId')->with(4)->andReturn($addressForm);

        $response = (new GetOrganizerByCustomDomainPublicAction($organizerRepository, $currentEventService, $customerLocaleResolver))('innocent976.yt');

        return $response->getData(true)['data'];
    }
}
