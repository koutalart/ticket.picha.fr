<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee;

use Closure;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\UniqueIdGenerationFailedException;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;

/**
 * S4 (PICHA_BOX_OFFICE_SECURITY_FINDINGS.md): IdHelper::publicId() has no
 * collision check. attendees.public_id now has a unique DB index (migration
 * 2026_09_05_000002_add_unique_index_to_attendees_public_id) — a collision
 * would previously have let one ticket's QR check in as another attendee.
 */
class AttendeePublicIdGenerator
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ?Closure                    $candidateFactory = null,
    )
    {
    }

    /**
     * @throws UniqueIdGenerationFailedException
     */
    public function generateUnique(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->candidateFactory
                ? ($this->candidateFactory)()
                : IdHelper::publicId(IdHelper::ATTENDEE_PREFIX);

            $existing = $this->attendeeRepository->findFirstWhere([
                AttendeeDomainObjectAbstract::PUBLIC_ID => $candidate,
            ]);

            if ($existing === null) {
                return $candidate;
            }
        }

        throw new UniqueIdGenerationFailedException(
            sprintf('Unable to generate a unique attendee public_id after %d attempts.', self::MAX_ATTEMPTS)
        );
    }
}
