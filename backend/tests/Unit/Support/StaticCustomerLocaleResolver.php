<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;

/**
 * Resolves every organizer and event to the same address form, without any repository.
 */
class StaticCustomerLocaleResolver extends CustomerLocaleResolver
{
    public function __construct(public CustomerAddressForm $addressForm = CustomerAddressForm::VOUS) {}

    public function addressFormForOrganizerId(?int $organizerId): CustomerAddressForm
    {
        return $this->addressForm;
    }

    public function addressFormForEventId(?int $eventId): CustomerAddressForm
    {
        return $this->addressForm;
    }
}
