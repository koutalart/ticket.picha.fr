<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Exceptions\InvalidZebraPrinterHostException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\BoxOffice\PrintBoxOfficeZplRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\PrintBoxOfficeZplDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintBoxOfficeZplHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PrintBoxOfficeZplAction extends BaseAction
{
    public function __construct(
        private readonly PrintBoxOfficeZplHandler $handler,
    ) {}

    public function __invoke(PrintBoxOfficeZplRequest $request, int $eventId, string $attendeePublicId): JsonResponse|Response
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        try {
            $this->handler->handle(new PrintBoxOfficeZplDTO(
                event_id: $eventId,
                attendee_public_id: $attendeePublicId,
                agent_user_id: $this->getAuthenticatedUser()->getId(),
                printer_host: $request->validated('printer_host'),
            ));
        } catch (InvalidZebraPrinterHostException $exception) {
            throw ValidationException::withMessages(['printer_host' => $exception->getMessage()]);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (ZebraPrinterUnreachableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_BAD_GATEWAY);
        }

        return $this->noContentResponse();
    }
}
