<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Public\GetOrderRefundProtectionPublicHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetOrderRefundProtectionActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetOrderRefundProtectionPublicHandler $handler,
    ) {}

    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $offer = $this->handler->handle($eventId, $orderShortId);
        } catch (ResourceNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ResourceConflictException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (UnauthorizedException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        return $this->jsonResponse($offer?->toArray(), wrapInData: true);
    }
}
