<?php

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Interfaces\IsSortable;
use HiEvents\DomainObjects\SortingAndFiltering\AllowedSorts;
use Illuminate\Support\Collection;

class ContactDomainObject extends Generated\ContactDomainObjectAbstract implements IsSortable
{
    private ?Collection $contactTags = null;

    public static function getDefaultSort(): string
    {
        return static::LAST_ORDER_AT;
    }

    public static function getDefaultSortDirection(): string
    {
        return 'desc';
    }

    public static function getAllowedSorts(): AllowedSorts
    {
        return new AllowedSorts([
            self::LAST_ORDER_AT => [
                'desc' => __('Most recent purchase first'),
                'asc' => __('Oldest purchase first'),
            ],
            self::TOTAL_SPENT => [
                'desc' => __('Highest spend first'),
                'asc' => __('Lowest spend first'),
            ],
            self::EVENTS_COUNT => [
                'desc' => __('Most events first'),
                'asc' => __('Fewest events first'),
            ],
            self::LAST_NAME => [
                'asc' => __('Name A-Z'),
                'desc' => __('Name Z-A'),
            ],
            self::CREATED_AT => [
                'desc' => __('Newest first'),
                'asc' => __('Oldest first'),
            ],
        ]);
    }

    public function hasEmailConsent(): bool
    {
        return self::isConsentActive($this->getEmailConsentAt(), $this->getEmailUnsubscribedAt());
    }

    public function hasSmsConsent(): bool
    {
        return self::isConsentActive($this->getSmsConsentAt(), $this->getSmsUnsubscribedAt());
    }

    public function getFullName(): string
    {
        return trim(($this->getFirstName() ?? '') . ' ' . ($this->getLastName() ?? ''));
    }

    public function setContactTags(?Collection $contactTags): self
    {
        $this->contactTags = $contactTags;

        return $this;
    }

    public function getContactTags(): ?Collection
    {
        return $this->contactTags;
    }

    private static function isConsentActive(?string $consentAt, ?string $unsubscribedAt): bool
    {
        if ($consentAt === null) {
            return false;
        }

        return $unsubscribedAt === null || strtotime($unsubscribedAt) < strtotime($consentAt);
    }
}
