<?php

namespace HiEvents\DomainObjects;

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventPageType;
use HiEvents\Helper\AddressHelper;

class EventSettingDomainObject extends Generated\EventSettingDomainObjectAbstract
{
    /**
     * @return string
     * @todo This should not be here.
     */
    public function getGetEmailFooterHtml(): string
    {
        if ($this->getEmailFooterMessage() === null) {
            return '';
        }

        return <<<HTML
<div style="color: #888; margin-top: 30px; margin-bottom: 30px; font-size: .9em;">
    {$this->getEmailFooterMessage()}
</div>
HTML;
    }

    /**
     * @return string[] Slugs of the dedicated event pages that have content, in display order
     */
    public function getAvailableEventPages(): array
    {
        $pages = $this->getEventPages();

        if (is_string($pages)) {
            $pages = json_decode($pages, true);
        }

        if (!is_array($pages)) {
            return [];
        }

        $available = [];
        foreach (EventPageType::cases() as $pageType) {
            if (!empty($pages[$pageType->contentKey()])) {
                $available[] = $pageType->value;
            }
        }

        return $available;
    }

    public function getAddressString(): string
    {
        return AddressHelper::formatAddress($this->getLocationDetails());
    }

    public function getAddress(): AddressDTO
    {
        return new AddressDTO(
            venue_name: $this->getLocationDetails()['venue_name'] ?? null,
            address_line_1: $this->getLocationDetails()['address_line_1'] ?? null,
            address_line_2: $this->getLocationDetails()['address_line_2'] ?? null,
            city: $this->getLocationDetails()['city'] ?? null,
            state_or_region: $this->getLocationDetails()['state_or_region'] ?? null,
            zip_or_postal_code: $this->getLocationDetails()['zip_or_postal_code'] ?? null,
            country: $this->getLocationDetails()['country'] ?? null,
        );
    }
}
