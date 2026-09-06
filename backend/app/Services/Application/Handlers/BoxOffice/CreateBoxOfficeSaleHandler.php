<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\Constants;
use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\Generated\BoxOfficeSaleDomainObjectAbstract;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\BoxOfficeSaleStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\BoxOfficePriceMismatchException;
use HiEvents\Exceptions\ProductNotScannableException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeSaleResultDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleItemDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Option A (PICHA_BOX_OFFICE_DESIGN_OPTIONS.md): a thin handler around the
 * unmodified CreateAttendeeHandler, carrying the guard rails the manual-sale
 * path is missing — server-side price (S1), a stock row lock (S2), and
 * idempotency (S3/D9). Cart checkouts (D4 option B) wrap N CreateAttendeeHandler
 * calls in the same transaction under one idempotency_key.
 */
class CreateBoxOfficeSaleHandler
{
    public function __construct(
        private readonly BoxOfficeSaleRepositoryInterface $boxOfficeSaleRepository,
        private readonly ProductPriceRepositoryInterface $productPriceRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly CreateAttendeeHandler $createAttendeeHandler,
        private readonly IsAuthorizedService $isAuthorizedService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @param  UserDomainObject|null  $agent  The authenticated agent, when the sale
     *                                        comes from the HTTP action. Passed so the box office event scope
     *                                        (D23) can be re-checked inside the locked transaction — an ADMIN
     *                                        may have revoked a BOX_OFFICE_OPERATOR's assignment between the
     *                                        action's guard and here (TOCTOU). Null for direct callers/tests
     *                                        that are not exercising operator scope.
     *
     * @throws BoxOfficePriceMismatchException
     * @throws ProductNotScannableException
     * @throws ResourceConflictException
     * @throws UnauthorizedException
     * @throws Throwable
     */
    public function handle(CreateBoxOfficeSaleDTO $dto, ?UserDomainObject $agent = null): BoxOfficeSaleResultDTO
    {
        return $this->databaseManager->transaction(function () use ($dto, $agent) {
            // AC-10: a sequential replay with the same idempotency_key
            // returns the sale already completed by the first call.
            if ($existing = $this->findCompletedSale($dto->idempotency_key)) {
                return $existing;
            }

            $items = $this->normalizedItems($dto);

            // idempotency_key is the table's only unique constraint, so any
            // violation here means a concurrent request won the race
            // between the check above and this insert (AC-11).
            try {
                $sale = $this->boxOfficeSaleRepository->create([
                    BoxOfficeSaleDomainObjectAbstract::IDEMPOTENCY_KEY => $dto->idempotency_key,
                    BoxOfficeSaleDomainObjectAbstract::EVENT_ID => $dto->event_id,
                    BoxOfficeSaleDomainObjectAbstract::AGENT_USER_ID => $dto->agent_user_id,
                    BoxOfficeSaleDomainObjectAbstract::PRODUCT_ID => $items[0]->product_id,
                    BoxOfficeSaleDomainObjectAbstract::PRODUCT_PRICE_ID => $items[0]->product_price_id,
                    BoxOfficeSaleDomainObjectAbstract::PAYMENT_METHOD => $dto->payment_method->name,
                    BoxOfficeSaleDomainObjectAbstract::AMOUNT => $dto->amount,
                    BoxOfficeSaleDomainObjectAbstract::AMOUNT_COLLECTED => $dto->amount_collected,
                    BoxOfficeSaleDomainObjectAbstract::STATUS => BoxOfficeSaleStatus::PENDING->name,
                    'phone' => $this->normalizePhone($dto->phone),
                ]);
            } catch (QueryException $exception) {
                // 23505 = unique_violation (Postgres). Anything else (a
                // dropped connection, a NOT NULL violation from a caller
                // bug, ...) is a real error and must not be swallowed as
                // if it were an idempotency race.
                if ($exception->getCode() !== '23505') {
                    throw $exception;
                }

                if ($existing = $this->findCompletedSale($dto->idempotency_key)) {
                    return $existing;
                }

                throw new ResourceConflictException(
                    __('A sale with this idempotency key is already being processed.')
                );
            }

            $lockedPrices = $this->lockPrices($items);

            $this->validateCartTotals($dto, $items, $lockedPrices);
            $this->validateScannableItems($items);
            $this->validateStock($items);

            // D23 TOCTOU: re-check the box office event scope now that we hold
            // the stock lock. An ADMIN could have revoked this operator's
            // event_box_office_operators row between the action's guard and
            // here. Placed AFTER price/scannable/stock so a sale that would be
            // rejected anyway never pays this extra query.
            if ($agent !== null) {
                $this->isAuthorizedService->validateBoxOfficeEventScope($dto->event_id, $agent);
            }

            $attendees = [];
            $itemRows = [];
            $ticketIndex = 0;

            foreach ($items as $item) {
                $unitPrice = $lockedPrices[$item->product_price_id]->getPrice();

                for ($i = 0; $i < $item->quantity; $i++) {
                    // CreateAttendeeHandler is reused unchanged (Option A) — it
                    // opens its own nested transaction (savepoint). If it throws,
                    // the whole transaction above (including the PENDING insert)
                    // rolls back too, satisfying AC-26.
                    $attendee = $this->createAttendeeHandler->handle(new CreateAttendeeDTO(
                        first_name: $dto->first_name,
                        last_name: $dto->last_name,
                        email: $this->attendeeEmail($dto, $ticketIndex),
                        product_id: $item->product_id,
                        event_id: $dto->event_id,
                        send_confirmation_email: false,
                        amount_paid: $unitPrice,
                        locale: $dto->locale,
                        product_price_id: $item->product_price_id,
                    ));

                    $order = $this->orderRepository->findById($attendee->getOrderId());
                    $attendees[] = $attendee;
                    $itemRows[] = [
                        'product_id' => $item->product_id,
                        'product_price_id' => $item->product_price_id,
                        'unit_amount' => $unitPrice,
                        'attendee_id' => $attendee->getId(),
                        'order_id' => $order->getId(),
                    ];
                    $ticketIndex++;
                }
            }

            $this->boxOfficeSaleRepository->createItems($sale->getId(), $itemRows);

            $firstAttendee = $attendees[0];
            $firstOrder = $this->orderRepository->findById($firstAttendee->getOrderId());

            $this->boxOfficeSaleRepository->updateFromArray($sale->getId(), [
                BoxOfficeSaleDomainObjectAbstract::ORDER_ID => $firstOrder->getId(),
                BoxOfficeSaleDomainObjectAbstract::ATTENDEE_ID => $firstAttendee->getId(),
                BoxOfficeSaleDomainObjectAbstract::STATUS => BoxOfficeSaleStatus::COMPLETED->name,
            ]);

            return new BoxOfficeSaleResultDTO($sale->getId(), $firstAttendee, $firstOrder, $attendees);
        });
    }

    private function findCompletedSale(string $idempotencyKey): ?BoxOfficeSaleResultDTO
    {
        $sale = $this->boxOfficeSaleRepository->findFirstWhere([
            BoxOfficeSaleDomainObjectAbstract::IDEMPOTENCY_KEY => $idempotencyKey,
        ]);

        if ($sale === null || $sale->getStatus() !== BoxOfficeSaleStatus::COMPLETED->name) {
            return null;
        }

        $saleItems = $this->boxOfficeSaleRepository->findItemsBySaleId($sale->getId());
        $attendees = [];

        foreach ($saleItems as $saleItem) {
            if ($saleItem->attendee_id !== null) {
                $attendees[] = $this->attendeeRepository->findById($saleItem->attendee_id);
            }
        }

        if ($attendees === []) {
            $attendees[] = $this->attendeeRepository->findById($sale->getAttendeeId());
        }

        return new BoxOfficeSaleResultDTO(
            $sale->getId(),
            $attendees[0],
            $this->orderRepository->findById($sale->getOrderId()),
            $attendees,
        );
    }

    /**
     * @param  CreateBoxOfficeSaleItemDTO[]  $items
     * @return array<int, ProductPriceDomainObject>
     */
    private function lockPrices(array $items): array
    {
        $priceIds = array_values(array_unique(array_map(
            static fn(CreateBoxOfficeSaleItemDTO $item) => $item->product_price_id,
            $items,
        )));
        sort($priceIds, SORT_NUMERIC);

        $locked = [];
        foreach ($priceIds as $priceId) {
            $locked[$priceId] = $this->productPriceRepository->lockForUpdateById($priceId);
        }

        return $locked;
    }

    /**
     * @param  CreateBoxOfficeSaleItemDTO[]  $items
     * @param  array<int, ProductPriceDomainObject|null>  $lockedPrices
     *
     * @throws BoxOfficePriceMismatchException
     */
    private function validateCartTotals(CreateBoxOfficeSaleDTO $dto, array $items, array $lockedPrices): void
    {
        $expected = 0.0;
        $allZero = true;

        foreach ($items as $item) {
            $serverPrice = $lockedPrices[$item->product_price_id]?->getPrice();

            if ($serverPrice === null) {
                throw new BoxOfficePriceMismatchException(
                    __('The amount does not match the current price of this ticket.')
                );
            }

            if (number_format($serverPrice, 2, '.', '') !== '0.00') {
                $allZero = false;
            }

            $expected += $serverPrice * $item->quantity;
        }

        if (number_format($expected, 2, '.', '') !== number_format($dto->amount, 2, '.', '')) {
            throw new BoxOfficePriceMismatchException(
                __('The amount does not match the current price of this ticket.')
            );
        }

        // D21: FREE only reflects products whose price is already 0.
        if ($dto->payment_method === BoxOfficePaymentMethod::FREE && ! $allZero) {
            throw new BoxOfficePriceMismatchException(
                __('FREE can only be used for a product whose price is 0 — inviting a normally-paid ticket is not supported.')
            );
        }
    }

    /**
     * @param  CreateBoxOfficeSaleItemDTO[]  $items
     *
     * @throws ProductNotScannableException
     */
    private function validateScannableItems(array $items): void
    {
        $productIds = array_unique(array_map(
            static fn(CreateBoxOfficeSaleItemDTO $item) => $item->product_id,
            $items,
        ));

        foreach ($productIds as $productId) {
            if (! $this->productRepository->hasActiveCheckInList($productId)) {
                throw new ProductNotScannableException(
                    __('This product is not attached to an active check-in list and would not be scannable at the door.')
                );
            }
        }
    }

    /**
     * @param  CreateBoxOfficeSaleItemDTO[]  $items
     *
     * @throws ResourceConflictException
     */
    private function validateStock(array $items): void
    {
        $qtyByPrice = [];

        foreach ($items as $item) {
            $key = $item->product_id . ':' . $item->product_price_id;
            $qtyByPrice[$key] = ($qtyByPrice[$key] ?? 0) + $item->quantity;
        }

        foreach ($qtyByPrice as $key => $quantity) {
            [$productId, $productPriceId] = array_map('intval', explode(':', $key));
            $remaining = $this->productRepository->getQuantityRemainingForProductPrice(
                $productId,
                $productPriceId,
            );

            if ($remaining !== Constants::INFINITE && $remaining < $quantity) {
                throw new ResourceConflictException(__('There are no tickets available for this price.'));
            }
        }
    }

    /**
     * @return CreateBoxOfficeSaleItemDTO[]
     */
    private function normalizedItems(CreateBoxOfficeSaleDTO $dto): array
    {
        if ($dto->items !== []) {
            return array_values($dto->items);
        }

        return [
            new CreateBoxOfficeSaleItemDTO(
                product_id: $dto->product_id,
                product_price_id: $dto->product_price_id,
                quantity: 1,
            ),
        ];
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+33' . substr($digits, 1);
        }

        return '+' . $digits;
    }

    private function attendeeEmail(CreateBoxOfficeSaleDTO $dto, int $ticketIndex): string
    {
        if ($dto->email !== '') {
            if ($ticketIndex === 0) {
                return $dto->email;
            }

            [$local, $domain] = explode('@', $dto->email, 2);

            return $local . '+t' . $ticketIndex . '@' . $domain;
        }

        $digits = preg_replace('/\D+/', '', $dto->phone) ?: '';
        $token = $digits !== '' ? $digits : substr($dto->idempotency_key, 0, 12);
        $suffix = $ticketIndex === 0 ? '' : '-' . $ticketIndex;

        return 'kiosk+' . $token . $suffix . '@guichet.example.test';
    }
}
