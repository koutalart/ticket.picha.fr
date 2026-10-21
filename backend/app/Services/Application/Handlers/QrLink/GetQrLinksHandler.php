<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use Illuminate\Support\Collection;

class GetQrLinksHandler
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
    ) {}

    /**
     * @return Collection<QrLinkDomainObject>
     */
    public function handle(int $organizerId): Collection
    {
        $qrLinks = $this->qrLinkRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findWhere(
                where: [
                    QrLinkDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                    [QrLinkDomainObjectAbstract::SMS_CAMPAIGN_ID, 'null', null],
                ],
                orderAndDirections: [new OrderAndDirection(QrLinkDomainObjectAbstract::CREATED_AT, 'desc')],
            );

        $totals = $this->qrLinkRepository->getOrderTotalsByQrLinkIds(
            $qrLinks->map(fn (QrLinkDomainObject $qrLink) => $qrLink->getId())->all()
        );

        return $qrLinks->each(function (QrLinkDomainObject $qrLink) use ($totals) {
            $qrLink->setOrderTotals(
                $totals[$qrLink->getId()]['orders_count'] ?? 0,
                $totals[$qrLink->getId()]['revenue_gross'] ?? 0.0,
            );
        });
    }
}
