<?php

declare(strict_types=1);

namespace Digit\Bracelets\Http\Actions;

use Digit\Bracelets\Domain\Exceptions\BraceletEventMismatchException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotAssignedException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotFoundException;
use Digit\Bracelets\Domain\Exceptions\InvalidBraceletPayloadException;
use Digit\Bracelets\Domain\Services\BraceletLookupService;
use Digit\Scan\Domain\Services\ScanCoordinatorService;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Bracelet-aware scan entry point for the PWA scanner (Phase 2). Pure
 * orchestration - does not reimplement anything from either Bracelets
 * (Phase 1) or Scan (Module 3):
 *
 *   device authenticated (AuthenticateScanDevice middleware, UNCHANGED)
 *     -> BraceletLookupService::resolveAttendeeId() [signature verified
 *        before any digit_bracelets DB read, per the validated security
 *        design - UNCHANGED]
 *     -> attendee_id resolved
 *     -> ScanCoordinatorService::scan() [Module 3 pipeline - UNCHANGED]
 *
 * Sits alongside ScanCheckInAction (which takes a raw attendee public_id
 * directly, used by the native/legacy flow) rather than replacing it -
 * both end up calling the same ScanCoordinatorService.
 */
class ScanBraceletCheckInAction extends BaseAction
{
    public function __construct(
        private readonly BraceletLookupService $braceletLookupService,
        private readonly ScanCoordinatorService $coordinator,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {
    }

    public function __invoke(string $checkInListShortId, Request $request): JsonResponse
    {
        $checkInList = $this->checkInListRepository->findFirstWhere(['short_id' => $checkInListShortId]);

        if (!$checkInList) {
            return $this->notFoundResponse();
        }

        $payload = (string) $request->input('payload');

        if ($payload === '') {
            return $this->errorResponse('Missing bracelet payload', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $device = $request->attributes->get('digit_scan_device');

        if ($device === null || $device->event_id === null) {
            // A device with no event scope cannot resolve a bracelet's
            // signing key - reject explicitly rather than guessing an
            // event from the check-in list. The event context must come
            // from the authenticated device, never from the URL or QR.
            return $this->errorResponse('This device is not scoped to an event', Response::HTTP_FORBIDDEN);
        }

        try {
            $attendeeId = $this->braceletLookupService->resolveAttendeeId($payload, (int) $device->event_id);
        } catch (InvalidBraceletPayloadException $e) {
            return $this->errorResponse('Invalid bracelet', Response::HTTP_UNPROCESSABLE_ENTITY, ['reason' => $e->reason()]);
        } catch (BraceletNotFoundException $e) {
            return $this->errorResponse('Unknown bracelet', Response::HTTP_NOT_FOUND, ['reason' => $e->reason()]);
        } catch (BraceletEventMismatchException $e) {
            return $this->errorResponse('Bracelet not valid for this event', Response::HTTP_FORBIDDEN, ['reason' => $e->reason()]);
        } catch (BraceletNotAssignedException $e) {
            return $this->errorResponse('Bracelet not assigned or revoked', Response::HTTP_UNPROCESSABLE_ENTITY, ['reason' => $e->reason()]);
        }

        $attendee = $this->attendeeRepository->findById($attendeeId);
        $action = (string) $request->input('action', 'check-in');
        $idempotencyKey = $request->header('Idempotency-Key') ?? (string) Str::uuid();
        $deviceIdentifier = sprintf('device:%d:%s', $device->id, $device->name);

        $outcome = $this->coordinator->scan(
            checkInListUuid: $checkInList->getShortId(),
            checkInListId: $checkInList->getId(),
            attendeeId: $attendeeId,
            attendeePublicId: $attendee->getPublicId(),
            action: $action,
            idempotencyKey: $idempotencyKey,
            deviceIdentifier: $deviceIdentifier,
            checkInUserIpAddress: (string) $request->ip(),
        );

        $statusCode = match ($outcome->result) {
            'recorded' => Response::HTTP_OK,
            'duplicate', 'duplicate_retry' => Response::HTTP_CONFLICT,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };

        return $this->jsonResponse([
            'attendee_id' => $outcome->attendeeId,
            'result' => $outcome->result,
            'attendee_check_in_id' => $outcome->attendeeCheckInId,
            'messages' => $outcome->messages,
        ], $statusCode);
    }
}
