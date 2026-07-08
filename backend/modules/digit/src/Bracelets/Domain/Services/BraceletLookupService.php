<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Services;

use Digit\Bracelets\Domain\Exceptions\BraceletEventMismatchException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotAssignedException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotFoundException;
use Digit\Bracelets\Domain\Exceptions\InvalidBraceletPayloadException;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use Digit\Bracelets\Security\EventSecurityKeyService;

/**
 * Prepares (but does not yet wire up) bracelet resolution for the future
 * scanner (Phase 2). Deliberately NOT called from ScanCoordinatorService
 * yet - this class only exposes the interface the scanner will eventually
 * use: QR payload in, attendee_id out, with every rejection reason kept
 * distinct for clear PWA messaging later.
 *
 * `deviceEventId` MUST come from the authenticated scanning device (see
 * Digit\Scan\Http\Middleware\AuthenticateScanDevice / digit_scan_devices),
 * never from the QR payload itself or any other client-supplied value -
 * the whole point is that the event context is trusted independently of
 * what the bracelet claims.
 *
 * Verification order matches the validated security design exactly:
 * signature check (no DB read) -> bracelet lookup -> event match ->
 * status check.
 */
class BraceletLookupService
{
    public function __construct(
        private readonly BraceletSignatureService $signatureService,
        private readonly EventSecurityKeyService $keyService,
    ) {
    }

    /**
     * @throws InvalidBraceletPayloadException
     * @throws BraceletNotFoundException
     * @throws BraceletEventMismatchException
     * @throws BraceletNotAssignedException
     */
    public function resolveAttendeeId(string $payload, int $deviceEventId): int
    {
        $eventSecret = $this->keyService->findSecret($deviceEventId);

        if ($eventSecret === null) {
            // No signing key initialized for this event yet - treat exactly
            // like an invalid signature. Never touches digit_bracelets.
            throw new InvalidBraceletPayloadException(
                "No security key configured for event {$deviceEventId}."
            );
        }

        // Step 1: signature verification, strictly before any DB read of
        // digit_bracelets - a forged/altered payload is rejected here
        // without ever touching the bracelets table.
        $code = $this->signatureService->verifyAndExtractCode($payload, $eventSecret);

        // Step 2: lookup.
        $bracelet = DigitBracelet::query()->where('code', $code)->first();

        if ($bracelet === null) {
            throw new BraceletNotFoundException("No bracelet found for code {$code}.");
        }

        // Step 3: event match (defense in depth - the secret used above is
        // already scoped to deviceEventId, so this should be unreachable,
        // but it is verified explicitly rather than assumed).
        if ($bracelet->event_id !== $deviceEventId) {
            throw new BraceletEventMismatchException(
                "Bracelet {$code} belongs to event {$bracelet->event_id}, not {$deviceEventId}."
            );
        }

        // Step 4: status.
        if (!$bracelet->status->isValidForEntry()) {
            throw new BraceletNotAssignedException($bracelet->status);
        }

        /** @var int $attendeeId ASSIGNED status guarantees attendee_id is set */
        $attendeeId = $bracelet->attendee_id;

        return $attendeeId;
    }
}
