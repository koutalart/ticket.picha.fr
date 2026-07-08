<?php

declare(strict_types=1);

namespace Digit\Bracelets\Http\Actions;

use Digit\Bracelets\Domain\DTO\BraceletDTO;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Guichet lookup: "this code -> this bracelet". Deliberately returns only
 * the bracelet itself (see BraceletDTO) - never attendee PII (name, email,
 * category...) beyond the raw attendee_id. A caller wanting to display a
 * name still has to go through Hi.Events' own native Attendee endpoints,
 * which already enforce their own authorization.
 *
 * Reuses Hi.Events' existing auth entirely: `auth:api` at the route-group
 * level (see routes/bracelets.php) plus isActionAuthorized() here, exactly
 * like every other event-scoped native action (e.g. GetCheckInListAction).
 * No Access module, no new authorization system.
 */
class GetBraceletAction extends BaseAction
{
    public function __invoke(string $code): JsonResponse
    {
        $bracelet = DigitBracelet::query()->where('code', $code)->first();

        if ($bracelet === null) {
            return $this->errorResponse('Bracelet not found', Response::HTTP_NOT_FOUND);
        }

        $this->isActionAuthorized($bracelet->event_id, EventDomainObject::class);

        return $this->jsonResponse(BraceletDTO::fromModel($bracelet)->toArray());
    }
}
