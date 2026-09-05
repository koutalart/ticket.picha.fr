<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\Generated\BoxOfficeSaleDomainObjectAbstract;
use HiEvents\DomainObjects\Status\BoxOfficeSaleStatus;
use HiEvents\Exceptions\BoxOfficePriceMismatchException;
use HiEvents\Exceptions\ProductNotScannableException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeSaleResultDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Option A (PICHA_BOX_OFFICE_DESIGN_OPTIONS.md): a thin handler around the
 * unmodified CreateAttendeeHandler, carrying the guard rails the manual-sale
 * path is missing — server-side price (S1), a stock row lock (S2), and
 * idempotency (S3/D9).
 */
class CreateBoxOfficeSaleHandler
{
    public function __construct(
        private readonly BoxOfficeSaleRepositoryInterface $boxOfficeSaleRepository,
        private readonly ProductPriceRepositoryInterface  $productPriceRepository,
        private readonly ProductRepositoryInterface       $productRepository,
        private readonly OrderRepositoryInterface         $orderRepository,
        private readonly AttendeeRepositoryInterface      $attendeeRepository,
        private readonly CreateAttendeeHandler            $createAttendeeHandler,
        private readonly DatabaseManager                  $databaseManager,
    )
    {
    }

    /**
     * @throws BoxOfficePriceMismatchException
     * @throws ProductNotScannableException
     * @throws ResourceConflictException
     * @throws Throwable
     */
    public function handle(CreateBoxOfficeSaleDTO $dto): BoxOfficeSaleResultDTO
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            // AC-10: a sequential replay with the same idempotency_key
            // returns the sale already completed by the first call.
            if ($existing = $this->findCompletedSale($dto->idempotency_key)) {
                return $existing;
            }

            // idempotency_key is the table's only unique constraint, so any
            // violation here means a concurrent request won the race
            // between the check above and this insert (AC-11).
            try {
                $sale = $this->boxOfficeSaleRepository->create([
                    BoxOfficeSaleDomainObjectAbstract::IDEMPOTENCY_KEY => $dto->idempotency_key,
                    BoxOfficeSaleDomainObjectAbstract::EVENT_ID => $dto->event_id,
                    BoxOfficeSaleDomainObjectAbstract::AGENT_USER_ID => $dto->agent_user_id,
                    BoxOfficeSaleDomainObjectAbstract::PRODUCT_ID => $dto->product_id,
                    BoxOfficeSaleDomainObjectAbstract::PRODUCT_PRICE_ID => $dto->product_price_id,
                    BoxOfficeSaleDomainObjectAbstract::PAYMENT_METHOD => $dto->payment_method->name,
                    BoxOfficeSaleDomainObjectAbstract::AMOUNT => $dto->amount,
                    BoxOfficeSaleDomainObjectAbstract::AMOUNT_COLLECTED => $dto->amount_collected,
                    BoxOfficeSaleDomainObjectAbstract::STATUS => BoxOfficeSaleStatus::PENDING->name,
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

            // Row lock on the stock we're about to sell (S2): any other sale
            // on the same product_price_id blocks here until we commit, so
            // the stock check below can never be raced.
            $productPrice = $this->productPriceRepository->lockForUpdateById($dto->product_price_id);

            $this->validatePrice($dto, $productPrice?->getPrice());
            $this->validateScannable($dto);
            $this->validateStock($dto);

            // CreateAttendeeHandler is reused unchanged (Option A) — it
            // opens its own nested transaction (savepoint). If it throws,
            // the whole transaction above (including the PENDING insert)
            // rolls back too, satisfying AC-26.
            $attendee = $this->createAttendeeHandler->handle(new CreateAttendeeDTO(
                first_name: $dto->first_name,
                last_name: $dto->last_name,
                email: $dto->email,
                product_id: $dto->product_id,
                event_id: $dto->event_id,
                send_confirmation_email: false,
                amount_paid: $dto->amount,
                locale: $dto->locale,
                product_price_id: $dto->product_price_id,
            ));

            $order = $this->orderRepository->findById($attendee->getOrderId());

            $this->boxOfficeSaleRepository->updateFromArray($sale->getId(), [
                BoxOfficeSaleDomainObjectAbstract::ORDER_ID => $order->getId(),
                BoxOfficeSaleDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
                BoxOfficeSaleDomainObjectAbstract::STATUS => BoxOfficeSaleStatus::COMPLETED->name,
            ]);

            return new BoxOfficeSaleResultDTO($sale->getId(), $attendee, $order);
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

        return new BoxOfficeSaleResultDTO(
            $sale->getId(),
            $this->attendeeRepository->findById($sale->getAttendeeId()),
            $this->orderRepository->findById($sale->getOrderId()),
        );
    }

    /**
     * @throws BoxOfficePriceMismatchException
     */
    private function validatePrice(CreateBoxOfficeSaleDTO $dto, ?float $serverPrice): void
    {
        if ($serverPrice === null || number_format($serverPrice, 2, '.', '') !== number_format($dto->amount, 2, '.', '')) {
            throw new BoxOfficePriceMismatchException(
                __('The amount does not match the current price of this ticket.')
            );
        }

        // D21 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): FREE only reflects a
        // product whose price is already 0 — it is not a mechanism to
        // comp a normally-paid ticket. The equality check above already
        // guarantees amount === serverPrice; this only needs to reject
        // FREE + a non-zero price.
        if ($dto->payment_method === BoxOfficePaymentMethod::FREE && number_format($serverPrice, 2, '.', '') !== '0.00') {
            throw new BoxOfficePriceMismatchException(
                __('FREE can only be used for a product whose price is 0 — inviting a normally-paid ticket is not supported.')
            );
        }
    }

    /**
     * @throws ProductNotScannableException
     */
    private function validateScannable(CreateBoxOfficeSaleDTO $dto): void
    {
        if (!$this->productRepository->hasActiveCheckInList($dto->product_id)) {
            throw new ProductNotScannableException(
                __('This product is not attached to an active check-in list and would not be scannable at the door.')
            );
        }
    }

    /**
     * @throws ResourceConflictException
     */
    private function validateStock(CreateBoxOfficeSaleDTO $dto): void
    {
        $remaining = $this->productRepository->getQuantityRemainingForProductPrice(
            $dto->product_id,
            $dto->product_price_id,
        );

        if ($remaining <= 0) {
            throw new ResourceConflictException(__('There are no tickets available for this price.'));
        }
    }
}
