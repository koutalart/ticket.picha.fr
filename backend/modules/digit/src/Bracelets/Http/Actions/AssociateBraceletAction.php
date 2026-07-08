<?php

declare(strict_types=1);

namespace Digit\Bracelets\Http\Actions;

use Digit\Bracelets\Domain\DTO\AssociateBraceletDTO;
use Digit\Bracelets\Domain\Exceptions\BraceletAssociationException;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use Digit\Bracelets\Domain\Services\BraceletAssociationService;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Associates a bracelet to an already-existing attendee - guichet pickup
 * for an online purchase today; Phase 4 on-site sale will call
 * BraceletAssociationService directly right after creating the attendee
 * via Hi.Events' native CreateAttendeeAction, without going through this
 * HTTP endpoint at all.
 */
class AssociateBraceletAction extends BaseAction
{
    public function __construct(
        private readonly BraceletAssociationService $associationService,
    ) {
    }

    public function __invoke(string $code, Request $request): JsonResponse
    {
        $bracelet = DigitBracelet::query()->where('code', $code)->first();

        if ($bracelet === null) {
            return $this->errorResponse('Bracelet not found', Response::HTTP_NOT_FOUND);
        }

        $this->isActionAuthorized($bracelet->event_id, EventDomainObject::class);

        $attendeeId = (int) $request->input('attendee_id');

        if ($attendeeId <= 0) {
            return $this->errorResponse('attendee_id is required', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->associationService->associate(new AssociateBraceletDTO(
                code: $code,
                attendeeId: $attendeeId,
                eventId: $bracelet->event_id,
            ));
        } catch (BraceletAssociationException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse($result->toArray());
    }
}
