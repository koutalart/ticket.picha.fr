<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Loyalty;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\WalletNotConfiguredException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Loyalty\GetLoyaltyWalletPassHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GetLoyaltyApplePassPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetLoyaltyWalletPassHandler $handler,
    ) {}

    public function __invoke(Request $request, string $publicId): Response|JsonResponse
    {
        try {
            return $this->handler->applePass($publicId)->toResponse($request);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_NOT_FOUND);
        } catch (WalletNotConfiguredException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
