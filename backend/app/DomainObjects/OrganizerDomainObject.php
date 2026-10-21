<?php

namespace HiEvents\DomainObjects;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrganizerDomainObject extends Generated\OrganizerDomainObjectAbstract
{
    private ?Collection $images = null;

    /**
     * @return Collection<EventDomainObject>|null
     */
    private ?Collection $events = null;

    private ?OrganizerSettingDomainObject $settings = null;

    /**
     * @var Collection<OrganizerFontDomainObject>|null
     */
    private ?Collection $organizerFonts = null;

    public function getOrganizerFonts(): ?Collection
    {
        return $this->organizerFonts;
    }

    public function setOrganizerFonts(?Collection $organizerFonts): self
    {
        $this->organizerFonts = $organizerFonts;

        return $this;
    }

    public function getImages(): ?Collection
    {
        return $this->images;
    }

    public function setImages(?Collection $images): self
    {
        $this->images = $images;

        return $this;
    }

    public function getEvents(): ?Collection
    {
        return $this->events;
    }

    public function setEvents(?Collection $events): self
    {
        $this->events = $events;

        return $this;
    }

    public function getOrganizerSettings(): ?OrganizerSettingDomainObject
    {
        return $this->settings;
    }

    public function setOrganizerSettings(?OrganizerSettingDomainObject $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    public function getSlug(): string
    {
        return Str::slug($this->name);
    }
}
