<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Exceptions\QrLinkReferenceException;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;

class UpdateQrLinkHandler
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly QrLinkAttributesBuilder $attributesBuilder,
    ) {}

    /**
     * @throws QrLinkReferenceException
     */
    public function handle(int $qrLinkId, UpsertQrLinkDTO $dto): QrLinkDomainObject
    {
        return $this->qrLinkRepository->updateByIdWhere(
            id: $qrLinkId,
            attributes: $this->attributesBuilder->build($dto),
            where: [QrLinkDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId],
        );
    }
}
