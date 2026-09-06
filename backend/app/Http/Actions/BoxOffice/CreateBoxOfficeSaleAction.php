<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\Exceptions\BoxOfficePriceMismatchException;
use HiEvents\Exceptions\ProductNotScannableException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\BoxOffice\CreateBoxOfficeSaleRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\BoxOffice\BoxOfficeSaleResource;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleItemDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CreateBoxOfficeSaleAction extends BaseAction
{
    public function __construct(
        private readonly CreateBoxOfficeSaleHandler $createBoxOfficeSaleHandler,
    ) {}

    public function __invoke(CreateBoxOfficeSaleRequest $request, int $eventId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        $itemDtos = array_map(
            static fn(array $item) => new CreateBoxOfficeSaleItemDTO(
                product_id: (int) $item['product_id'],
                product_price_id: (int) $item['product_price_id'],
                quantity: (int) $item['quantity'],
            ),
            $request->validated('items') ?? [],
        );

        if ($itemDtos === []) {
            $itemDtos = [
                new CreateBoxOfficeSaleItemDTO(
                    product_id: (int) $request->validated('product_id'),
                    product_price_id: (int) $request->validated('product_price_id'),
                    quantity: 1,
                ),
            ];
        }

        try {
            $result = $this->createBoxOfficeSaleHandler->handle(
                new CreateBoxOfficeSaleDTO(
                    event_id: $eventId,
                    agent_user_id: $this->getAuthenticatedUser()->getId(),
                    product_id: $itemDtos[0]->product_id,
                    product_price_id: $itemDtos[0]->product_price_id,
                    phone: $request->validated('phone') ?? '',
                    first_name: $request->validated('first_name') ?? '',
                    last_name: $request->validated('last_name') ?? '',
                    email: $request->validated('email') ?? '',
                    locale: $request->validated('locale'),
                    amount: (float) $request->validated('amount'),
                    payment_method: BoxOfficePaymentMethod::fromName($request->validated('payment_method')),
                    amount_collected: (float) $request->validated('amount_collected'),
                    idempotency_key: $request->validated('idempotency_key'),
                    items: $itemDtos,
                ),
                $this->getAuthenticatedUser(),
            );
        } catch (BoxOfficePriceMismatchException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        } catch (ProductNotScannableException|ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(
            resource: BoxOfficeSaleResource::class,
            data: $result,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
