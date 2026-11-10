<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\Exceptions\UniqueIdGenerationFailedException;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use Illuminate\Support\Str;

class QrLinkShortIdGenerator
{
    private const SHORT_ID_LENGTH = 7;

    private const SHORT_ID_ATTEMPTS = 10;

    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
    ) {}

    /**
     * @throws UniqueIdGenerationFailedException
     */
    public function generate(): string
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
