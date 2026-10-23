<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Generated\OrganizerCalendarDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\OrganizerCalendarRepositoryInterface;

class OrganizerCalendarResolver
{
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const SLUG_MAX_LENGTH = 100;

    public function __construct(
        private readonly OrganizerCalendarRepositoryInterface $calendarRepository,
    ) {}

    public static function isValidSlug(string $slug): bool
    {
        return strlen($slug) <= self::SLUG_MAX_LENGTH && preg_match('/^'.self::SLUG_PATTERN.'$/', $slug) === 1;
    }

    /**
     * @throws OrganizerCalendarNotFoundException
     */
    public function findBySlug(string $slug): OrganizerCalendarDomainObject
    {
        $calendar = self::isValidSlug($slug)
            ? $this->calendarRepository->findFirstWhere([OrganizerCalendarDomainObjectAbstract::SLUG => $slug])
            : null;

        if (! $calendar instanceof OrganizerCalendarDomainObject) {
            throw new OrganizerCalendarNotFoundException(__('This calendar does not exist.', [], CustomerLocale::get()));
        }

        return $calendar;
    }
}
