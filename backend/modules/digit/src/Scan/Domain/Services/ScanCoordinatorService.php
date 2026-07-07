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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $this->dispatchScanLog($previous->withResult('duplicate_retry'), $deviceIdentifier);

            return $previous;
        }

        if ($this->validityCache->isKnownInvalid($checkInListId, $attendeeId)) {
            $outcome = ScanOutcomeDTO::rejected($attendeeId, 'Ticket is not valid for this check-in list');
            $this->dispatchScanLog($outcome, $deviceIdentifier);

            return $outcome;
        }

        $outcome = $this->lockService->withBestEffortLock(
            $checkInListId,
            $attendeeId,
            fn() => $this->attemptCoreCheckIn(
                $checkInListUuid,
                $checkInListId,
                $attendeePublicId,
                $action,
                $checkInUserIpAddress,
                $attendeeId,
            )
        );

        $this->idempotencyService->remember($idempotencyKey, $outcome);
        $this->dispatchScanLog($outcome, $deviceIdentifier);

        return $outcome;
    }

    private function attemptCoreCheckIn(
        string $checkInListUuid,
        int $checkInListId,
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
            // Root cause found in the Module 3 validation suite (Test 2):
            // Hi.Events' own CreateAttendeeCheckInPublicHandler writes the
            // check-in row and COMMITS it first, then - afterwards, outside
            // that transaction - dispatches a CheckinEvent whose listener
            // (WebhookEventListener, not itself queued) synchronously pushes
            // DispatchCheckInWebhookJob onto the Redis-backed queue. If Redis
            // is unreachable at that point, the push throws and lands here,
            // even though the check-in was already durably saved moments
            // earlier. Silently returning "rejected" in that case would be a
            // false negative: the DB - the one true source of correctness in
            // this system - already has the check-in, but the caller/scanner
            // would be told it failed. Rather than trust the exception type,
            // we re-check the actual DB state before deciding: if a live
            // check-in row exists for this attendee on this check-in list,
            // report success (or duplicate, if it existed before this call);
            // only fall back to "rejected" if the DB genuinely has no record
            // of it. This query never touches Redis, so it cannot itself
            // introduce a new failure mode.
            $existing = DB::table('attendee_check_ins')
                ->where('attendee_id', $attendeeId)
                ->where('check_in_list_id', $checkInListId)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                Log::warning('digit.scan.core_check_in_exception_but_db_confirms_recorded', [
                    'attendee_id' => $attendeeId,
                    'check_in_list_id' => $checkInListId,
                    'exception_class' => get_class($e),
                    'error' => $e->getMessage(),
                ]);

                return ScanOutcomeDTO::success($attendeeId, (int) $existing->id);
            }

            Log::error('digit.scan.core_check_in_unexpected_exception', [
                'attendee_id' => $attendeeId,
                'check_in_list_id' => $checkInListId,
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
                'trace_top' => collect(explode("\n", $e->getTraceAsString()))->take(12)->implode(' || '),
            ]);

            return ScanOutcomeDTO::rejected($attendeeId, __('Unable to process scan, please try again'));
        }

        if (!empty($result->errors->errors)) {
            return ScanOutcomeDTO::duplicate($attendeeId, $result->errors->toArray());
        }

        return ScanOutcomeDTO::success($attendeeId, $result->attendeeCheckIns->first()?->getId());
    }

    /**
     * Scan logging is best-effort bookkeeping - it is NOT part of the scan
     * correctness guarantee (the DB unique constraint on attendee_check_ins
     * is the only thing that is). Dispatching a queued job pushes onto the
     * queue connection (Redis) synchronously, as part of the current HTTP
     * request. If Redis is unreachable, that push throws a RedisException,
     * and without this guard it takes down the whole request with a 500 -
     * even though the check-in itself was already durably written to the
     * database moments earlier. Same fail-open philosophy as ScanLockService
     * and FailOpenThrottle: log the failure, never let it block the response.
     */
    private function dispatchScanLog(ScanOutcomeDTO $outcome, string $deviceIdentifier): void
    {
        try {
            RecordScanLogJob::dispatch($outcome, $deviceIdentifier);
        } catch (Throwable $e) {
            Log::warning('digit.scan.record_log_dispatch_failed', [
                'error' => $e->getMessage(),
                'device_identifier' => $deviceIdentifier,
            ]);
        }
    }
}
