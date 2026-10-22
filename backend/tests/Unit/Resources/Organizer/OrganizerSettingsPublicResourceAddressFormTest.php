<?php

namespace Tests\Unit\Resources\Organizer;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Resources\Organizer\OrganizerSettingsPublicResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrganizerSettingsPublicResourceAddressFormTest extends TestCase
{
    private function resolve(OrganizerSettingDomainObject $settings): array
    {
        return (new OrganizerSettingsPublicResource($settings))->toArray(Request::create('/'));
    }

    public function test_public_settings_expose_vous_by_default(): void
    {
        $data = $this->resolve((new OrganizerSettingDomainObject)->setId(1)->setOrganizerId(1));

        $this->assertSame('vous', $data['customer_address_form']);
    }

    public function test_public_settings_expose_tu_when_enabled(): void
    {
        $data = $this->resolve(
            (new OrganizerSettingDomainObject)->setId(4)->setOrganizerId(4)->setCustomerAddressForm('tu')
        );

        $this->assertSame('tu', $data['customer_address_form']);
    }
}
