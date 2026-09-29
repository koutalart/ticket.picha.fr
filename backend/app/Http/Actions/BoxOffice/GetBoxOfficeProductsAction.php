<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\BoxOffice\BoxOfficeProductResource;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeProductsHandler;
use Illuminate\Http\JsonResponse;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — GET /events/{event_id}/box-office/products:
 * the sellable catalogue for the Kiosk "Vente" screen. Same authorization as
 * the sale itself (validateBoxOfficeEventScope): an ADMIN/ORGANIZER of the
 * event's account, or a BOX_OFFICE_OPERATOR with an ACTIVE assignment.
 */
class GetBoxOfficeProductsAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeProductsHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        return $this->resourceResponse(
            resource: BoxOfficeProductResource::class,
            data: $this->handler->handle($eventId),
        );
    }
}
