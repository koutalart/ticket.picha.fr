<?php

namespace HiEvents\DomainObjects;

use BackedEnum;
use HiEvents\DomainObjects\Enums\TrackingPixelProvider;
use UnitEnum;

class OrganizerSettingDomainObject extends Generated\OrganizerSettingDomainObjectAbstract
{
    public function getSocialMediaHandle(string $platform): ?string
    {
        $handles = $this->getSocialMediaHandles();

        return $handles[$platform] ?? null;
    }

    public function getHomepageThemeSetting(string $key, string $default = ''): ?string
    {
        $settings = $this->getHomepageThemeSettings();

        if (isset($settings[$key]) && ($settings[$key] instanceof UnitEnum)) {
            return $settings[$key] instanceof BackedEnum
                ? $settings[$key]->value
                : $settings[$key]->name;
        }

        return $settings[$key] ?? $default;
    }

    public function getEnabledFacebookPixelId(): ?string
    {
        foreach ($this->getTrackingPixels() ?? [] as $pixel) {
            if (($pixel['provider'] ?? null) === TrackingPixelProvider::FACEBOOK_PIXEL->value
                && ! empty($pixel['enabled'])
                && ! empty($pixel['pixel_id'])) {
                return (string) $pixel['pixel_id'];
            }
        }

        return null;
    }

    public function isMetaConversionsApiConfigured(): bool
    {
        return ! empty($this->getMetaConversionsApiToken()) && $this->getEnabledFacebookPixelId() !== null;
    }
}
