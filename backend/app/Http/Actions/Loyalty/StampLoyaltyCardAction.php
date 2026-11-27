<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Loyalty;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Loyalty\StampLoyaltyCardRequest;
use HiEvents\Resources\Loyalty\LoyaltyCardResource;
use HiEvents\Services\Application\Handlers\Loyalty\StampLoyaltyCardHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StampLoyaltyCardAction extends BaseAction
{
    public function __construct(
        private readonly StampLoyaltyCardHandler $handler,
    ) {}

    public function __invoke(StampLoyaltyCardRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $result = $this->handler->handle($organizerId, (string) $request->validated('code'));
        } catch (LoyaltyCardException|ResourceNotFoundException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return $this->jsonResponse([
            'card' => (new LoyaltyCardResource($result->card))->toArray($request),
            'reward_unlocked' => $result->reward_unlocked,
        ], wrapInData: true);
    }
}
