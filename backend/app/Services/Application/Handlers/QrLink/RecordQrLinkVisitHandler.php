<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\DTO\QrLinkVisitResultDTO;
use HiEvents\Services\Domain\QrLink\DTO\QrLinkVisitContextDTO;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use HiEvents\Services\Domain\QrLink\QrLinkVisitRecorder;

class RecordQrLinkVisitHandler
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly QrLinkVisitRecorder $visitRecorder,
        private readonly QrLinkUrlService $urlService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(string $shortId, QrLinkVisitContextDTO $context): QrLinkVisitResultDTO
    {
        /** @var QrLinkDomainObject|null $qrLink */
        $qrLink = $this->qrLinkRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findFirstWhere([
                QrLinkDomainObjectAbstract::SHORT_ID => strtolower($shortId),
                QrLinkDomainObjectAbstract::STATUS => QrLinkStatus::ACTIVE->value,
            ]);

        if ($qrLink === null) {
            throw new ResourceNotFoundException(__('QR code not found'));
        }

        $recorded = $this->visitRecorder->record($qrLink, $context);

        return new QrLinkVisitResultDTO(
            destination_type: $qrLink->getDestinationType(),
            redirect_url: $this->urlService->getRedirectUrl($qrLink, $qrLink->getEvent()),
            recorded: $recorded,
        );
    }
}
