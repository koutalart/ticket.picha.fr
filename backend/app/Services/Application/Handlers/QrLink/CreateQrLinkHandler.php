<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\UniqueIdGenerationFailedException;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use Illuminate\Support\Str;

class CreateQrLinkHandler
{
    private const SHORT_ID_LENGTH = 7;

    private const SHORT_ID_ATTEMPTS = 10;

    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly QrLinkAttributesBuilder $attributesBuilder,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws UniqueIdGenerationFailedException
     */
    public function handle(UpsertQrLinkDTO $dto): QrLinkDomainObject
    {
        return $this->qrLinkRepository->create([
            ...$this->attributesBuilder->build($dto),
            'account_id' => $dto->accountId,
            'organizer_id' => $dto->organizerId,
            'short_id' => $this->generateShortId(),
        ]);
    }

    /**
     * @throws UniqueIdGenerationFailedException
     */
    private function generateShortId(): string
    {
        for ($attempt = 0; $attempt < self::SHORT_ID_ATTEMPTS; $attempt++) {
            $shortId = Str::lower(Str::random(self::SHORT_ID_LENGTH));

            if ($this->qrLinkRepository->countWhere([QrLinkDomainObjectAbstract::SHORT_ID => $shortId]) === 0) {
                return $shortId;
            }
        }

        throw new UniqueIdGenerationFailedException(__('Unable to generate a unique QR code identifier'));
    }
}
