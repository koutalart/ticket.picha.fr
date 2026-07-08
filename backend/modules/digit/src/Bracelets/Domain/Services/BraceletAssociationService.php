<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Services;

use Digit\Bracelets\Domain\DTO\AssociateBraceletDTO;
use Digit\Bracelets\Domain\DTO\BraceletDTO;
use Digit\Bracelets\Domain\Enums\BraceletStatus;
use Digit\Bracelets\Domain\Exceptions\BraceletAssociationException;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for associating/revoking/replacing bracelets,
 * used both by guichet pickup (attendee already exists from an online
 * purchase) and, later, by on-site sale (Phase 4, not built yet - it will
 * call associate() right after creating the attendee via Hi.Events' native
 * CreateAttendeeAction). This service knows nothing about how the attendee
 * was created, only that it exists.
 *
 * The DB partial unique index (digit_bracelets_unique_active_attendee) is
 * the actual, unconditional guarantee that an attendee never ends up with
 * two active bracelets - the checks in this service are a fast, friendly
 * first line of defense, not the source of truth.
 */
class BraceletAssociationService
{
    public function associate(AssociateBraceletDTO $dto): BraceletDTO
    {
        return DB::transaction(function () use ($dto) {
            $bracelet = $this->findForEventOrFail($dto->code, $dto->eventId);

            if (!$bracelet->status->canAssociate()) {
                throw new BraceletAssociationException(
                    "Bracelet {$dto->code} cannot be associated from status {$bracelet->status->value}."
                );
            }

            $this->assertAttendeeHasNoActiveBracelet($dto->attendeeId, $dto->eventId, excludingBraceletId: $bracelet->id);

            $bracelet->attendee_id = $dto->attendeeId;
            $bracelet->status = BraceletStatus::ASSIGNED;
            $bracelet->assigned_at = now();
            $bracelet->save();

            return BraceletDTO::fromModel($bracelet->refresh());
        });
    }

    public function revoke(string $code, int $eventId, ?string $reason = null): BraceletDTO
    {
        return DB::transaction(function () use ($code, $eventId) {
            $bracelet = $this->findForEventOrFail($code, $eventId);

            if ($bracelet->status->isTerminal()) {
                throw new BraceletAssociationException(
                    "Bracelet {$code} is already {$bracelet->status->value}."
                );
            }

            $bracelet->status = BraceletStatus::REVOKED;
            $bracelet->revoked_at = now();
            $bracelet->save();

            return BraceletDTO::fromModel($bracelet->refresh());
        });
    }

    /**
     * Replaces a lost/broken bracelet with a new one for the same attendee,
     * as a single atomic transaction: revokes the old bracelet and
     * associates the new one together, so no intermediate state can ever
     * be observed where the attendee has zero or two active bracelets.
     */
    public function replace(string $oldCode, string $newCode, int $eventId): BraceletDTO
    {
        return DB::transaction(function () use ($oldCode, $newCode, $eventId) {
            $oldBracelet = $this->findForEventOrFail($oldCode, $eventId);

            if ($oldBracelet->status !== BraceletStatus::ASSIGNED) {
                throw new BraceletAssociationException(
                    "Bracelet {$oldCode} is not currently assigned, nothing to replace."
                );
            }

            $attendeeId = $oldBracelet->attendee_id;

            $oldBracelet->status = BraceletStatus::REVOKED;
            $oldBracelet->revoked_at = now();
            $oldBracelet->save();

            $newBracelet = $this->findForEventOrFail($newCode, $eventId);

            if (!$newBracelet->status->canAssociate()) {
                throw new BraceletAssociationException(
                    "Replacement bracelet {$newCode} cannot be associated from status {$newBracelet->status->value}."
                );
            }

            $newBracelet->attendee_id = $attendeeId;
            $newBracelet->status = BraceletStatus::ASSIGNED;
            $newBracelet->assigned_at = now();
            $newBracelet->save();

            return BraceletDTO::fromModel($newBracelet->refresh());
        });
    }

    private function findForEventOrFail(string $code, int $eventId): DigitBracelet
    {
        $bracelet = DigitBracelet::query()
            ->where('code', $code)
            ->where('event_id', $eventId)
            ->first();

        if ($bracelet === null) {
            throw new BraceletAssociationException("Bracelet {$code} not found for event {$eventId}.");
        }

        return $bracelet;
    }

    private function assertAttendeeHasNoActiveBracelet(int $attendeeId, int $eventId, int $excludingBraceletId): void
    {
        $existing = DigitBracelet::query()
            ->where('event_id', $eventId)
            ->where('attendee_id', $attendeeId)
            ->whereNotIn('status', [BraceletStatus::REVOKED->value, BraceletStatus::COMPROMISED->value])
            ->where('id', '!=', $excludingBraceletId)
            ->exists();

        if ($existing) {
            throw new BraceletAssociationException(
                "Attendee {$attendeeId} already has an active bracelet for event {$eventId}."
            );
        }
    }
}
