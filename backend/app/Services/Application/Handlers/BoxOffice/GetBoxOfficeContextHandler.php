<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventBoxOfficeOperatorDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventBoxOfficeOperatorDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Status\BoxOfficeOperatorStatus;
use HiEvents\Helper\PhoneCallingCode;
use HiEvents\Repository\Interfaces\EventBoxOfficeOperatorRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeContextEventDTO;
use Illuminate\Support\Collection;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — the events the Kiosk shell may operate
 * for. For a BOX_OFFICE_OPERATOR this is exactly their ACTIVE assignments
 * (the server, not localStorage, is the authority for which event(s) they
 * see). For an ORGANIZER/ADMIN opening the shell it is their account's
 * events.
 */
class GetBoxOfficeContextHandler
{
    public function __construct(
        private readonly EventBoxOfficeOperatorRepositoryInterface $operatorRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
    ) {}

    public function handle(int $userId, string $role, int $accountId): Collection
    {
        if ($role === Role::BOX_OFFICE_OPERATOR->name) {
            $eventIds = $this->operatorRepository
                ->findWhere([
                    EventBoxOfficeOperatorDomainObjectAbstract::USER_ID => $userId,
                    EventBoxOfficeOperatorDomainObjectAbstract::STATUS => BoxOfficeOperatorStatus::ACTIVE->name,
                ])
                ->map(fn (EventBoxOfficeOperatorDomainObject $operator) => $operator->getEventId())
                ->all();

            if ($eventIds === []) {
                return collect();
            }

            return $this->mapEvents(
                $this->eventRepository->findWhereIn(EventDomainObjectAbstract::ID, $eventIds)
            );
        }

        return $this->mapEvents($this->eventRepository->findWhere([
            EventDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]));
    }

    /**
     * @param  Collection<int, EventDomainObject>  $events
     * @return Collection<int, BoxOfficeContextEventDTO>
     */
    private function mapEvents(Collection $events): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        $settingsByEventId = $this->eventSettingsRepository
            ->findWhereIn('event_id', $events->map(fn (EventDomainObject $event) => $event->getId())->all())
            ->keyBy(fn (EventSettingDomainObject $settings) => $settings->getEventId());

        return $events->map(function (EventDomainObject $event) use ($settingsByEventId) {
            $country = PhoneCallingCode::iso2FromLocationDetails(
                $settingsByEventId->get($event->getId())?->getLocationDetails()
            );

            return new BoxOfficeContextEventDTO(
                id: $event->getId(),
                title: $event->getTitle(),
                currency: $event->getCurrency(),
                timezone: $event->getTimezone(),
                country: $country,
                calling_code: PhoneCallingCode::fromIso2($country),
            );
        });
    }
}
