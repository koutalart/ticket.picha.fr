<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Loyalty;

use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Loyalty\EnrollLoyaltyCardRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Loyalty\LoyaltyCardResourcePublic;
use HiEvents\Services\Application\Handlers\Loyalty\DTO\EnrollLoyaltyCardDTO;
use HiEvents\Services\Application\Handlers\Loyalty\EnrollLoyaltyCardHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EnrollLoyaltyCardPublicAction extends BaseAction
{
    public function __construct(
        private readonly EnrollLoyaltyCardHandler $handler,
    ) {}

    public function __invoke(EnrollLoyaltyCardRequest $request, int $organizerId): JsonResponse
    {
        try {
            $card = $this->handler->handle(new EnrollLoyaltyCardDTO(
                organizer_id: $organizerId,
                first_name: (string) $request->validated('first_name'),
                email: $request->validated('email'),
                phone: $request->validated('phone'),
            ));
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_NOT_FOUND);
        } catch (LoyaltyCardException $exception) {
            throw ValidationException::withMessages(['first_name' => $exception->getMessage()]);
        }

        return $this->resourceResponse(LoyaltyCardResourcePublic::class, $card, ResponseCodes::HTTP_CREATED);
    }
}
