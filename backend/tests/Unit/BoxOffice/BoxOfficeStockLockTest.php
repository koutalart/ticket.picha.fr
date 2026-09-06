<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red-until-fixed) test for AC-8: unlike StockRaceCharacterizationTest
 * (which proves the CURRENT bug, S2), this test asserts the CORRECT
 * behavior CreateBoxOfficeSaleHandler must have once it locks the stock row
 * (ProductPriceRepository::lockForUpdateById(), PICHA_BOX_OFFICE_DESIGN_OPTIONS.md
 * Option A). It is expected to error today (class doesn't exist) and, once
 * implemented with the lock in place, to PASS — including under genuine
 * concurrency (not exercised here at the DB-session level, since the
 * handler doesn't exist yet to race against; StockRaceCharacterizationTest
 * already demonstrates the two-PDO-connection technique this would need).
 */
class BoxOfficeStockLockTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private function makeDto(int $eventId, int $agentUserId, int $productId, int $productPriceId): CreateBoxOfficeSaleDTO
    {
        return new CreateBoxOfficeSaleDTO(
            event_id: $eventId,
            agent_user_id: $agentUserId,
            product_id: $productId,
            product_price_id: $productPriceId,
            phone: '+33612345678',
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            locale: 'en',
            amount: 25.00,
            payment_method: BoxOfficePaymentMethod::CASH,
            amount_collected: 25.00,
            idempotency_key: \Illuminate\Support\Str::uuid()->toString(),
        );
    }

    /** AC-8 */
    public function test_two_concurrent_sales_on_last_ticket_only_one_succeeds(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 1,
            quantitySold: 0,
        );
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $handler->handle($this->makeDto($event->id, $user->id, $product->id, $productPrice->id));

        $this->expectException(ResourceConflictException::class);

        try {
            $handler->handle($this->makeDto($event->id, $user->id, $product->id, $productPrice->id));
        } finally {
            self::assertSame(
                (int)ProductPrice::find($productPrice->id)->initial_quantity_available,
                ProductPrice::find($productPrice->id)->quantity_sold,
                'AC-8: quantity_sold must never exceed what was available',
            );
        }
    }
}
