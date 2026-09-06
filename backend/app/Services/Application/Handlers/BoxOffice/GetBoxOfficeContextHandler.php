<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventBoxOfficeOperatorDomainObject;
use HiEvents\DomainObjects\Generated\EventBoxOfficeOperatorDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Status\BoxOfficeOperatorStatus;
use HiEvents\Repository\Interfaces\EventBoxOfficeOperatorRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
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

            return $this->eventRepository->findWhereIn(EventDomainObjectAbstract::ID, $eventIds);
        }

        return $this->eventRepository->findWhere([
            EventDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);
    }
}
