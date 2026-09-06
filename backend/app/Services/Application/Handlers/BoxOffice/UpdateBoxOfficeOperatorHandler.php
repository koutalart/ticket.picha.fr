<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\EventBoxOfficeOperatorDomainObject;
use HiEvents\DomainObjects\Generated\EventBoxOfficeOperatorDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventBoxOfficeOperatorRepositoryInterface;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — flip an operator's assignment for one
 * event (REVOKED / ACTIVE). Scoped to the event: other assignments of the
 * same operator are untouched. The users / account_users rows are kept.
 */
class UpdateBoxOfficeOperatorHandler
{
    public function __construct(
        private readonly EventBoxOfficeOperatorRepositoryInterface $operatorRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, int $userId, string $status): EventBoxOfficeOperatorDomainObject
    {
        $assignment = $this->operatorRepository->findFirstWhere([
            EventBoxOfficeOperatorDomainObjectAbstract::EVENT_ID => $eventId,
            EventBoxOfficeOperatorDomainObjectAbstract::USER_ID => $userId,
        ]);

        if ($assignment === null) {
            throw new ResourceNotFoundException(__('This operator is not assigned to this event.'));
        }

        return $this->operatorRepository->updateFromArray($assignment->getId(), [
            EventBoxOfficeOperatorDomainObjectAbstract::STATUS => $status,
        ]);
    }
}
