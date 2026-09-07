<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeStatsDTO;

class GetBoxOfficeStatsHandler
{
    public function __construct(
        private readonly BoxOfficeSaleRepositoryInterface $boxOfficeSaleRepository,
    ) {}

    public function handle(int $eventId, UserDomainObject $user, Role $role): BoxOfficeStatsDTO
    {
        $agentUserId = $role === Role::BOX_OFFICE_OPERATOR
            ? $user->getId()
            : null;

        $result = $this->boxOfficeSaleRepository->aggregateCompletedForKiosk($eventId, $agentUserId);

        return new BoxOfficeStatsDTO(
            sales_count: $result['sales_count'],
            ticket_count: $result['ticket_count'],
            total_amount: $result['total_amount'],
            total_collected: $result['total_collected'],
            by_payment_method: $result['by_payment_method'],
            by_agent: $result['by_agent'],
        );
    }
}
