<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Exceptions\PaymentAlreadyStartedException;
use HiEvents\Exceptions\RefundProtectionUnavailableException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\UpdateOrderRefundProtectionRequest;
use HiEvents\Services\Application\Handlers\Order\Public\UpdateOrderRefundProtectionPublicHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class UpdateOrderRefundProtectionActionPublic extends BaseAction
{
    public function __construct(
        private readonly UpdateOrderRefundProtectionPublicHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(UpdateOrderRefundProtectionRequest $request, int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $offer = $this->handler->handle($eventId, $orderShortId, $request->boolean('enabled'));
        } catch (RefundProtectionUnavailableException $e) {
            throw ValidationException::withMessages(['enabled' => $e->getMessage()]);
        } catch (ResourceNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ResourceConflictException|PaymentAlreadyStartedException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (UnauthorizedException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        return $this->jsonResponse($offer?->toArray(), wrapInData: true);
    }
}
