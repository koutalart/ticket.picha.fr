<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Exceptions\QrLinkReferenceException;
use HiEvents\Exceptions\UniqueIdGenerationFailedException;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use HiEvents\Services\Domain\QrLink\QrLinkShortIdGenerator;

class CreateQrLinkHandler
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly QrLinkAttributesBuilder $attributesBuilder,
        private readonly QrLinkShortIdGenerator $shortIdGenerator,
    ) {}

    /**
     * @throws QrLinkReferenceException
     * @throws UniqueIdGenerationFailedException
     */
    public function handle(UpsertQrLinkDTO $dto): QrLinkDomainObject
    {
        return $this->qrLinkRepository->create([
            ...$this->attributesBuilder->build($dto),
            'account_id' => $dto->accountId,
            'organizer_id' => $dto->organizerId,
            'short_id' => $this->shortIdGenerator->generate(),
        ]);
    }
}
