<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\EventBoxOfficeOperatorDomainObject;
use HiEvents\DomainObjects\Generated\EventBoxOfficeOperatorDomainObjectAbstract;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\EventBoxOfficeOperatorRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — the operators assigned to one event,
 * each with its own status, for the ADMIN "Box office operators" screen.
 */
class GetBoxOfficeOperatorsHandler
{
    public function __construct(
        private readonly EventBoxOfficeOperatorRepositoryInterface $operatorRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function handle(int $eventId): Collection
    {
        $assignments = $this->operatorRepository->findWhere([
            EventBoxOfficeOperatorDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($assignments->isEmpty()) {
            return collect();
        }

        $usersById = $this->userRepository
            ->findWhereIn('id', $assignments->map(fn (EventBoxOfficeOperatorDomainObject $a) => $a->getUserId())->all())
            ->keyBy(fn (UserDomainObject $user) => $user->getId());

        return $assignments->map(function (EventBoxOfficeOperatorDomainObject $assignment) use ($usersById) {
            /** @var UserDomainObject|null $user */
            $user = $usersById->get($assignment->getUserId());
            $assignment->setUser($user);

            return $assignment;
        });
    }
}
