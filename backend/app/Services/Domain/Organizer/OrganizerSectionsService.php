<?php

namespace HiEvents\Services\Domain\Organizer;

use HiEvents\DomainObjects\Enums\OrganizerSection;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidOrganizerSectionException;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;

class OrganizerSectionsService
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    /**
     * @throws OrganizerNotFoundException
     */
    public function findOrganizer(int $organizerId): OrganizerDomainObject
    {
        $organizer = $this->organizerRepository->findFirst($organizerId);

        if ($organizer === null) {
            throw new OrganizerNotFoundException(
                __('Organizer :id not found', ['id' => $organizerId])
            );
        }

        return $organizer;
    }

    /**
     * @param  string[]  $keys
     * @return string[]
     *
     * @throws InvalidOrganizerSectionException
     */
    public function normalize(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(static fn ($key) => strtolower(trim((string) $key)), $keys),
            static fn (string $key) => $key !== '',
        )));

        if ($keys === []) {
            throw new InvalidOrganizerSectionException(__('At least one section is required.'));
        }

        $unknown = array_diff($keys, OrganizerSection::valuesArray());
        if ($unknown !== []) {
            throw new InvalidOrganizerSectionException(__('Unknown section(s): :sections. Valid sections: :valid', [
                'sections' => implode(', ', $unknown),
                'valid' => OrganizerSection::valuesCsv(', '),
            ]));
        }

        return array_values(array_filter(
            OrganizerSection::valuesArray(),
            static fn (string $section) => in_array($section, $keys, true),
        ));
    }

    /**
     * @return string[]|null
     */
    public static function sectionsOf(OrganizerDomainObject $organizer): ?array
    {
        $sections = $organizer->getEnabledSections();

        if (is_string($sections)) {
            $sections = json_decode($sections, true);
        }

        return is_array($sections) ? array_values($sections) : null;
    }

    /**
     * @param  string[]|null  $sections
     */
    public function setSections(int $organizerId, ?array $sections): void
    {
        $this->organizerRepository->updateFromArray($organizerId, [
            'enabled_sections' => $sections,
        ]);
    }
}
