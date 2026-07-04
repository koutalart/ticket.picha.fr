<?php

declare(strict_types=1);

namespace Digit\Scan\Domain\Services;

use Digit\Scan\Domain\DTO\ScanOutcomeDTO;
use Digit\Scan\Jobs\RecordScanLogJob;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Services\Application\Handlers\CheckInList\Public\CreateAttendeeCheckInPublicHandler;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\CreateAttendeeCheckInPublicDTO;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

readonly class ScanCoordinatorService
{
    public function __construct(
        private CreateAttendeeCheckInPublicHandler $coreHandler,
        private ScanLockService                    $lockService,
        private ScanIdempotencyService              $idempotencyService,
        private TicketValidityCacheService          $validityCache,
    ) {
    }

    public function scan(
        string $checkInListUuid,
        int $checkInListId,
        int $attendeeId,
        string $attendeePublicId,
        string $action,
        string $idempotencyKey,
        string $deviceIdentifier,
        string $checkInUserIpAddress,
    ): ScanOutcomeDTO {
        if ($previous = $this->idempotencyService->find($idempotencyKey)) {
            RecordScanLogJob::dispatch($previous->withResult('duplicate_retry'), $deviceIdentifier);

            return $previous;
        }

        if ($this->validityCache->isKnownInvalid($checkInListId, $attendeeId)) {
            $outcome = ScanOutcomeDTO::rejected($attendeeId, 'Ticket is not valid for this check-in list');
            RecordScanLogJob::dispatch($outcome, $deviceIdentifier);

            return $outcome;
        }

        $outcome = $this->lockService->withBestEffortLock(
            $checkInListId,
            $attendeeId,
            fn() => $this->attemptCoreCheckIn(
                $checkInListUuid,
                $attendeePublicId,
                $action,
                $checkInUserIpAddress,
                $attendeeId,
            )
        );

        $this->idempotencyService->remember($idempotencyKey, $outcome);
        RecordScanLogJob::dispatch($outcome, $deviceIdentifier);

        return $outcome;
    }

    private function attemptCoreCheckIn(
        string $checkInListUuid,
        string $attendeePublicId,
        string $action,
        string $checkInUserIpAddress,
        int $attendeeId,
    ): ScanOutcomeDTO {
        try {
            $result = $this->coreHandler->handle(CreateAttendeeCheckInPublicDTO::from([
                'checkInListUuid' => $checkInListUuid,
                'checkInUserIpAddress' => $checkInUserIpAddress,
                'attendeesAndActions' => new Collection([
                    (object)['public_id' => $attendeePublicId, 'action' => $action],
                ]),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            return ScanOutcomeDTO::duplicate($attendeeId, ['Attendee is already checked in']);
        } catch (CannotCheckInException $e) {
            return ScanOutcomeDTO::rejected($attendeeId, $e->getMessage());
        } catch (Throwable $e) {
            return ScanOutcomeDTO::rejected($attendeeId, __('Unable to process scan, please try again'));
        }

        if (!empty($result->errors->errors)) {
            return ScanOutcomeDTO::duplicate($attendeeId, $result->errors->toArray());
        }

        return ScanOutcomeDTO::success($attendeeId, $result->attendeeCheckIns->first()?->getId());
    }
}
