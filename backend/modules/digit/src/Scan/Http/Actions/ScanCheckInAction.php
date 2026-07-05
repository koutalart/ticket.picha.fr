<?php

declare(strict_types=1);

namespace Digit\Scan\Http\Actions;

use Digit\Scan\Domain\Services\ScanCoordinatorService;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ScanCheckInAction extends BaseAction
{
    public function __construct(
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

        $attendeePublicId = (string) $request->input('public_id');
        $action = (string) $request->input('action', 'check-in');
        $idempotencyKey = $request->header('Idempotency-Key') ?? (string) Str::uuid();

        // Device identity now comes from AuthenticateScanDevice (verified token),
        // not the client-supplied X-Device-Id header, which is no longer trusted
        // for anything beyond an optional human-readable fallback label.
        $device = $request->attributes->get('digit_scan_device');
        $deviceIdentifier = $device !== null
            ? sprintf('device:%d:%s', $device->id, $device->name)
            : (string) $request->header('X-Device-Id', 'unknown');

        $attendee = $this->attendeeRepository->findFirstWhere(['public_id' => $attendeePublicId]);

        if (!$attendee) {
            return $this->errorResponse('Attendee not found', Response::HTTP_NOT_FOUND);
        }

        $outcome = $this->coordinator->scan(
            checkInListUuid: $checkInList->getShortId(),
            checkInListId: $checkInList->getId(),
            attendeeId: $attendee->getId(),
            attendeePublicId: $attendeePublicId,
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
