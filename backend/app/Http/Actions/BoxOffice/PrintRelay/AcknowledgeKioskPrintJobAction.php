<?php

namespace HiEvents\Http\Actions\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Exceptions\InvalidKioskPrintRelayTokenException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\BoxOffice\AcknowledgeKioskPrintJobRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\AcknowledgeKioskPrintJobHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\AcknowledgeKioskPrintJobDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AcknowledgeKioskPrintJobAction extends BaseAction
{
    public function __construct(
        private readonly AcknowledgeKioskPrintJobHandler $handler,
    ) {}

    public function __invoke(AcknowledgeKioskPrintJobRequest $request, int $jobId): JsonResponse|Response
    {
        try {
            $job = $this->handler->handle(new AcknowledgeKioskPrintJobDTO(
                relay_token: $request->bearerToken(),
                job_id: $jobId,
                status: KioskPrintJobStatus::fromName($request->validated('status')),
                error: $request->validated('error'),
            ));
        } catch (InvalidKioskPrintRelayTokenException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNAUTHORIZED);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse([
            'id' => $job->getId(),
            'status' => $job->getStatus(),
            'attempts' => $job->getAttempts(),
        ], wrapInData: true);
    }
}
