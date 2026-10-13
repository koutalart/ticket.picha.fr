<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks;

use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use Illuminate\Http\Response;

class DeleteQrLinkAction extends BaseAction
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
    ) {}

    public function __invoke(int $organizerId, int $qrLinkId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $deleted = $this->qrLinkRepository->deleteWhere([
            QrLinkDomainObjectAbstract::ID => $qrLinkId,
            QrLinkDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        return $deleted ? $this->deletedResponse() : $this->notFoundResponse();
    }
}
