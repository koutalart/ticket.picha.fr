<?php

declare(strict_types=1);

namespace Digit\Bracelets\Http\Actions;

use Digit\Bracelets\Domain\Exceptions\BraceletAssociationException;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use Digit\Bracelets\Domain\Services\BraceletAssociationService;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RevokeBraceletAction extends BaseAction
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

        try {
            $result = $this->associationService->revoke(
                code: $code,
                eventId: $bracelet->event_id,
                reason: $request->input('reason'),
            );
        } catch (BraceletAssociationException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse($result->toArray());
    }
}
