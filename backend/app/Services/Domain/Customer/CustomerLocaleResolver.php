<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Customer;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrganizerSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use Throwable;

/**
 * Single place deciding the locale of what customers receive (e-mails, SMS, attendance pages):
 * French in « tu » when the organizer chose it, the customer locale otherwise.
 */
class CustomerLocaleResolver
{
    public function __construct(
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function forOrganizerId(?int $organizerId): string
    {
        return CustomerLocale::forAddressForm($this->addressFormForOrganizerId($organizerId));
    }

    public function forEvent(?EventDomainObject $event): string
    {
        return $this->forOrganizerId($event?->getOrganizerId());
    }

    public function forEventId(?int $eventId): string
    {
        return CustomerLocale::forAddressForm($this->addressFormForEventId($eventId));
    }

    /**
     * « tu » only when every event belongs to an organizer in « tu ».
     *
     * @param  iterable<int|null>  $eventIds
     */
    public function forEventIds(iterable $eventIds): string
    {
        $addressForm = null;

        foreach (collect($eventIds)->unique() as $eventId) {
            $addressForm = $this->addressFormForEventId($eventId);

            if ($addressForm !== CustomerAddressForm::TU) {
                break;
            }
        }

        return CustomerLocale::forAddressForm($addressForm ?? CustomerAddressForm::VOUS);
    }

    public function addressFormForOrganizerId(?int $organizerId): CustomerAddressForm
    {
        if ($organizerId === null) {
            return CustomerAddressForm::VOUS;
        }

        try {
            /** @var OrganizerSettingDomainObject|null $settings */
            $settings = $this->organizerSettingsRepository->findFirstWhere([
                OrganizerSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ]);
        } catch (Throwable) {
            return CustomerAddressForm::VOUS;
        }

        return CustomerAddressForm::tryFrom((string) $settings?->getCustomerAddressForm()) ?? CustomerAddressForm::VOUS;
    }

    public function addressFormForEventId(?int $eventId): CustomerAddressForm
    {
        if ($eventId === null) {
            return CustomerAddressForm::VOUS;
        }

        try {
            /** @var EventDomainObject|null $event */
            $event = $this->eventRepository->findFirst($eventId);
        } catch (Throwable) {
            return CustomerAddressForm::VOUS;
        }

        return $this->addressFormForOrganizerId($event?->getOrganizerId());
    }
}
