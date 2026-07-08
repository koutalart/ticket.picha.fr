<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Enums;

/**
 * Deliberately does NOT include a "checked_in"/"used"/"consumed" state.
 * Whether a bracelet's attendee has actually checked in lives exclusively
 * in Hi.Events' native `attendee_check_ins` table (single source of truth,
 * same principle already applied throughout the Scan module). A bracelet's
 * status only ever describes the bracelet-attendee association lifecycle.
 *
 * Normal path:   GENERATED -> PRINTED -> ASSIGNED -> REVOKED
 * Lot incident:  GENERATED|PRINTED|ASSIGNED -> COMPROMISED (any prior state)
 *
 * REVOKED and COMPROMISED are both terminal. REVOKED is the individual-unit
 * path (lost/broken/replaced). COMPROMISED is the batch/lot-level path (an
 * export file leaked, a suspect lot in transit) - operationally distinct
 * even though both simply stop the bracelet from validating at scan time.
 */
enum BraceletStatus: string
{
    case GENERATED = 'GENERATED';
    case PRINTED = 'PRINTED';
    case ASSIGNED = 'ASSIGNED';
    case REVOKED = 'REVOKED';
    case COMPROMISED = 'COMPROMISED';

    /**
     * Only an ASSIGNED bracelet is valid for entry. Every other status is
     * rejected at scan/lookup time (see BraceletLookupService), each with
     * its own distinct rejection reason.
     */
    public function isValidForEntry(): bool
    {
        return $this === self::ASSIGNED;
    }

    public function isTerminal(): bool
    {
        return $this === self::REVOKED || $this === self::COMPROMISED;
    }

    /**
     * Whether this status counts toward the "one active bracelet per
     * attendee" rule (mirrors the partial unique DB index).
     */
    public function isActiveAssociation(): bool
    {
        return !$this->isTerminal();
    }

    public function canAssociate(): bool
    {
        return $this === self::GENERATED || $this === self::PRINTED;
    }
}
