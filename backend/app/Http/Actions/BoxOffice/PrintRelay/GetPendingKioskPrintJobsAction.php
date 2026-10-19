<?php

namespace HiEvents\Http\Actions\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\KioskPrintJobDomainObject;
use HiEvents\Exceptions\InvalidKioskPrintRelayTokenException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\GetPendingKioskPrintJobsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetPendingKioskPrintJobsAction extends BaseAction
{
    public function __construct(
        private readonly GetPendingKioskPrintJobsHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $jobs = $this->handler->handle($request->bearerToken());
        } catch (InvalidKioskPrintRelayTokenException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNAUTHORIZED);
        }

        return $this->jsonResponse(
            $jobs->map(static fn (KioskPrintJobDomainObject $job): array => [
                'id' => $job->getId(),
                'attendee_public_id' => $job->getAttendeePublicId(),
                'attempts' => $job->getAttempts(),
                'zpl' => $job->getZpl(),
            ])->values()->all(),
            wrapInData: true,
        );
    }
}
