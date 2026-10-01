<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrderFilterDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;

/**
 * @extends BaseRepository<OrderDomainObject>
 */
class OrderRepository extends BaseRepository implements OrderRepositoryInterface
{
    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            [OrderDomainObjectAbstract::EVENT_ID, '=', $eventId],
            [OrderDomainObjectAbstract::STATUS, '!=', OrderStatus::RESERVED->name],
            [OrderDomainObjectAbstract::STATUS, '!=', OrderStatus::ABANDONED->name],
        ];

        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(
                        DB::raw(
                            sprintf(
                                "(%s||' '||%s)",
                                OrderDomainObjectAbstract::FIRST_NAME,
                                OrderDomainObjectAbstract::LAST_NAME
                            )
                        ), 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::LAST_NAME, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::PUBLIC_ID, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::EMAIL, 'ilike', '%' . $params->query . '%');
            };
        }

        if (!empty($params->filter_fields)) {
            $this->applyFilterFields($params, OrderDomainObject::getAllowedFilterFields());
        }

        $this->model = $this->model->orderBy(
            column: $this->validateSortColumn($params->sort_by, OrderDomainObject::class),
            direction: $this->validateSortDirection($params->sort_direction, OrderDomainObject::class),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    public function findByOrganizerId(int $organizerId, int $accountId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            ['orders.status', '!=', OrderStatus::RESERVED->name],
            ['orders.status', '!=', OrderStatus::ABANDONED->name],
        ];

        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(
                        DB::raw(
                            sprintf(
                                "(%s||' '||%s)",
                                OrderDomainObjectAbstract::FIRST_NAME,
                                OrderDomainObjectAbstract::LAST_NAME
                            )
                        ), 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::LAST_NAME, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::PUBLIC_ID, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::EMAIL, 'ilike', '%' . $params->query . '%');
            };
        }

        if (!empty($params->filter_fields)) {
            $this->applyFilterFields($params, OrderDomainObject::getAllowedFilterFields());
        }

        $this->model = $this->model
            ->select('orders.*')
            ->join('events', 'orders.event_id', '=', 'events.id')
            ->where('events.organizer_id', $organizerId)
            ->where('events.account_id', $accountId);

        $sortBy = $this->validateSortColumn($params->sort_by, OrderDomainObject::class);
        $this->model = $this->model->orderBy(
            column: 'orders.' . $sortBy,
            direction: $this->validateSortDirection($params->sort_direction, OrderDomainObject::class),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    public function getOrderItems(int $orderId)
    {
        return $this->handleResults(
            $this->model->find($orderId)->orderItems,
            OrderItemDomainObject::class
        );
    }

    public function getAttendees(int $orderId)
    {
        return $this->handleResults(
            $this->model->find($orderId)->attendees,
            AttendeeDomainObject::class
        );
    }

    public function addOrderItem(array $data): OrderItemDomainObject
    {
        $orderItem = $this->initModel(OrderItem::class)->create($data);

        return $this->handleSingleResult($orderItem, OrderItemDomainObject::class);
    }

    /**
     * @param string $orderShortId
     * @return OrderDomainObject|null
     */
    public function findByShortId(string $orderShortId): ?OrderDomainObject
    {
        return $this->findFirstByField('short_id', $orderShortId);
    }

    public function getDomainObject(): string
    {
        return OrderDomainObject::class;
    }

    protected function getModel(): string
    {
        return Order::class;
    }

    public function findOrdersAssociatedWithProducts(int $eventId, array $productIds, array $orderStatuses): Collection
    {
        return $this->handleResults(
            $this->model
                ->whereHas('order_items', static function (Builder $query) use ($productIds) {
                    $query->whereIn('product_id', $productIds);
                })
                ->whereIn('status', $orderStatuses)
                ->where('event_id', $eventId)
                ->get()
        );
    }

    public function countOrdersAssociatedWithProducts(int $eventId, array $productIds, array $orderStatuses): int
    {
        $count = $this->model
            ->whereHas('order_items', static function (Builder $query) use ($productIds) {
                $query->whereIn('product_id', $productIds);
            })
            ->whereIn('status', $orderStatuses)
            ->where('event_id', $eventId)
            ->count();

        $this->resetModel();

        return $count;
    }

    public function countActivePromoCodeUsage(int $promoCodeId): int
    {
        $count = $this->model
            ->where('promo_code_id', $promoCodeId)
            ->where(static function (Builder $query) {
                $query->whereIn('status', [
                    OrderStatus::COMPLETED->name,
                    OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
                ])->orWhere(static function (Builder $reserved) {
                    $reserved->where('status', OrderStatus::RESERVED->name)
                        ->where('reserved_until', '>', now());
                });
            })
            ->count();

        $this->resetModel();

        return $count;
    }

    public function getAllOrdersForAdmin(
        ?string $search = null,
        int $perPage = 20,
        ?string $sortBy = 'created_at',
        ?string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $this->model = $this->model
            ->select('orders.*')
            ->join('events', 'orders.event_id', '=', 'events.id')
            ->join('accounts', 'events.account_id', '=', 'accounts.id');

        if ($search) {
            $this->model = $this->model->where(function ($q) use ($search) {
                $q->where(OrderDomainObjectAbstract::EMAIL, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::FIRST_NAME, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::LAST_NAME, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::PUBLIC_ID, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::SHORT_ID, 'ilike', '%' . $search . '%');
            });
        }

        $this->model = $this->model->where('orders.status', '!=', OrderStatus::RESERVED->name)
            ->where('orders.status', '!=', OrderStatus::ABANDONED->name);

        $allowedSortColumns = ['created_at', 'total_gross', 'email', 'first_name', 'last_name'];
        $sortColumn = in_array($sortBy, $allowedSortColumns, true) ? $sortBy : 'created_at';
        $sortDir = in_array(strtolower($sortDirection), ['asc', 'desc']) ? $sortDirection : 'desc';

        $this->model = $this->model->orderBy('orders.' . $sortColumn, $sortDir);

        $this->loadRelation(new Relationship(EventDomainObject::class, nested: [
            new Relationship(AccountDomainObject::class, name: 'account')
        ], name: 'event'));

        return $this->paginate($perPage);
    }

    public function hasCompletedPaidOrderForAccount(int $accountId): bool
    {
        $exists = $this->model
            ->join('events', 'orders.event_id', '=', 'events.id')
            ->join('stripe_payments', 'orders.id', '=', 'stripe_payments.order_id')
            ->where('events.account_id', $accountId)
            ->where('orders.payment_status', OrderPaymentStatus::PAYMENT_RECEIVED->name)
            ->whereNotNull('stripe_payments.payment_intent_id')
            ->exists();

        $this->resetModel();

        return $exists;
    }

    public function paginateForBoxOffice(int $eventId, BoxOfficeOrderFilterDTO $filter): LengthAwarePaginator
    {
        $query = $this->boxOfficeOrdersQuery($eventId);

        if ($filter->channel === BoxOfficeOrderFilterDTO::CHANNEL_BOX_OFFICE) {
            $query->whereNotNull('box_office_sales.id');
        } elseif ($filter->channel === BoxOfficeOrderFilterDTO::CHANNEL_ONLINE) {
            $query->whereNull('box_office_sales.id');
        }

        if ($filter->agent_user_id !== null) {
            $query->where('box_office_sales.agent_user_id', $filter->agent_user_id);
        }

        if ($filter->cancelled) {
            $query->where('orders.status', OrderStatus::CANCELLED->name);
        }

        if ($filter->not_checked_in) {
            $query->where('orders.status', '!=', OrderStatus::CANCELLED->name)
                ->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('attendees as pending_attendees')
                        ->whereColumn('pending_attendees.order_id', 'orders.id')
                        ->whereNull('pending_attendees.deleted_at')
                        ->where('pending_attendees.status', '!=', 'CANCELLED')
                        ->whereNotExists(function ($checkIns) {
                            $checkIns->selectRaw('1')
                                ->from('attendee_check_ins')
                                ->whereColumn('attendee_check_ins.attendee_id', 'pending_attendees.id')
                                ->whereNull('attendee_check_ins.deleted_at');
                        });
                });
        }

        $search = trim((string) $filter->query);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($nested) use ($like) {
                $nested
                    ->where('orders.first_name', 'ilike', $like)
                    ->orWhere('orders.last_name', 'ilike', $like)
                    ->orWhereRaw("(COALESCE(orders.first_name, '') || ' ' || COALESCE(orders.last_name, '')) ilike ?", [$like])
                    ->orWhere('orders.email', 'ilike', $like)
                    ->orWhere('orders.public_id', 'ilike', $like)
                    ->orWhere('box_office_sales.phone', 'ilike', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->selectRaw('1')
                            ->from('attendees as search_attendees')
                            ->whereColumn('search_attendees.order_id', 'orders.id')
                            ->whereNull('search_attendees.deleted_at')
                            ->where(function ($names) use ($like) {
                                $names
                                    ->where('search_attendees.first_name', 'ilike', $like)
                                    ->orWhere('search_attendees.last_name', 'ilike', $like)
                                    ->orWhereRaw("(COALESCE(search_attendees.first_name, '') || ' ' || COALESCE(search_attendees.last_name, '')) ilike ?", [$like])
                                    ->orWhere('search_attendees.email', 'ilike', $like)
                                    ->orWhere('search_attendees.public_id', 'ilike', $like);
                            });
                    });
            });
        }

        return $query
            ->orderByDesc('orders.id')
            ->paginate(
                perPage: $filter->per_page,
                columns: ['*'],
                pageName: 'page',
                page: max(1, $filter->page),
            );
    }

    public function findForBoxOffice(int $eventId, string $orderPublicId): ?object
    {
        return $this->boxOfficeOrdersQuery($eventId)
            ->where('orders.public_id', $orderPublicId)
            ->first();
    }

    private function boxOfficeOrdersQuery(int $eventId): \Illuminate\Database\Query\Builder
    {
        return Order::query()
            ->where('orders.event_id', $eventId)
            ->whereIn('orders.status', [
                OrderStatus::COMPLETED->name,
                OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
                OrderStatus::CANCELLED->name,
            ])
            ->leftJoin('box_office_sales', function ($join) {
                $join->on('box_office_sales.order_id', '=', 'orders.id')
                    ->where('box_office_sales.status', 'COMPLETED');
            })
            ->leftJoin('users as agents', 'agents.id', '=', 'box_office_sales.agent_user_id')
            ->select([
                'orders.public_id',
                'orders.created_at',
                'orders.first_name',
                'orders.last_name',
                'orders.email',
                'orders.total_gross',
                'orders.currency',
                'orders.status',
                'orders.payment_status',
                'box_office_sales.id as box_office_sale_id',
                'box_office_sales.phone',
                'box_office_sales.payment_method',
                'agents.first_name as agent_first_name',
                'agents.last_name as agent_last_name',
            ])
            ->selectRaw('(select count(*) from attendees where attendees.order_id = orders.id and attendees.deleted_at is null) as ticket_count')
            ->selectRaw('(select count(distinct attendee_check_ins.attendee_id) from attendee_check_ins where attendee_check_ins.order_id = orders.id and attendee_check_ins.deleted_at is null) as checked_in_count')
            ->toBase();
    }
}
