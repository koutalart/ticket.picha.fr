<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks\Public;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Resources\QrLink\QrLinkPublicResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetQrLinkPublicAction extends BaseAction
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
    ) {}

    public function __invoke(string $shortId): JsonResponse|Response
    {
        $qrLink = $this->qrLinkRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findFirstWhere([
                QrLinkDomainObjectAbstract::SHORT_ID => strtolower($shortId),
                QrLinkDomainObjectAbstract::STATUS => QrLinkStatus::ACTIVE->value,
            ]);

        if ($qrLink === null) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(QrLinkPublicResource::class, $qrLink);
    }
}
