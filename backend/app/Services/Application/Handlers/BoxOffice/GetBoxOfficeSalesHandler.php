<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeSaleListItemDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeSalesPageDTO;

class GetBoxOfficeSalesHandler
{
    public function __construct(
        private readonly BoxOfficeSaleRepositoryInterface $boxOfficeSaleRepository,
    ) {}

    public function handle(int $eventId, UserDomainObject $user, Role $role, int $page = 1, string $search = ''): BoxOfficeSalesPageDTO
    {
        $page = max(1, $page);
        $agentUserId = $role === Role::BOX_OFFICE_OPERATOR ? $user->getId() : null;
        $result = $this->boxOfficeSaleRepository->paginateCompletedForKiosk($eventId, $agentUserId, $page, 20, $search);

        $items = array_map(
            static fn (array $row) => new BoxOfficeSaleListItemDTO(
                id: $row['id'],
                created_at: $row['created_at'],
                payment_method: $row['payment_method'],
                amount: $row['amount'],
                amount_collected: $row['amount_collected'],
                phone: $row['phone'],
                ticket_count: $row['ticket_count'],
                agent_user_id: $row['agent_user_id'],
                agent_name: $row['agent_name'],
                attendee_name: $row['attendee_name'],
                attendee_public_id: $row['attendee_public_id'],
                order_public_id: $row['order_public_id'],
                attendee_public_ids: $row['attendee_public_ids'] ?? [],
                checked_in_count: (int) ($row['checked_in_count'] ?? 0),
            ),
            $result['items'],
        );

        return new BoxOfficeSalesPageDTO(
            items: $items,
            total: $result['total'],
            current_page: $result['current_page'],
            last_page: $result['last_page'],
            per_page: $result['per_page'],
        );
    }
}
