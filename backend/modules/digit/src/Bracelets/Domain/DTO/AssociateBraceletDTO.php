<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\DTO;

/**
 * Input to BraceletAssociationService::associate(). `code` is the bracelet's
 * plain code (not the full "DGT1.code.signature" QR payload) - association
 * happens at a guichet/admin desk via authenticated staff, not via a raw QR
 * scan, so there is no signature to verify at this point.
 */
readonly class AssociateBraceletDTO
{
    public function __construct(
        public string $code,
        public int $attendeeId,
        public int $eventId,
    ) {
    }
}
