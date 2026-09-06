<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\Generated\EventBoxOfficeOperatorDomainObjectAbstract;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — TOCTOU on operator revocation.
 *
 * The action's guard (isBoxOfficeActionAuthorized) runs before the handler's
 * transaction. An ADMIN can revoke the operator's event_box_office_operators
 * row in that gap. CreateBoxOfficeSaleHandler re-checks the scope inside its
 * locked transaction (after the stock lock), so the revoked sale rolls back
 * with nothing written.
 */
class BoxOfficeOperatorToctouTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    private function makeDto(int $eventId, int $agentUserId, int $productId, int $productPriceId): CreateBoxOfficeSaleDTO
    {
        return new CreateBoxOfficeSaleDTO(
            event_id: $eventId,
            agent_user_id: $agentUserId,
            product_id: $productId,
            product_price_id: $productPriceId,
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            locale: 'en',
            amount: 25.00,
            payment_method: BoxOfficePaymentMethod::CASH,
            amount_collected: 25.00,
            idempotency_key: Str::uuid()->toString(),
        );
    }

    /**
     * Sequence: guard already passed (row ACTIVE) -> ADMIN revokes -> handler
     * runs. No box_office_sales row, no order, quantity_sold unchanged.
     */
    public function test_revocation_between_action_guard_and_handler_creates_no_sale(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $agent = $this->hydrateUserForAccount($operator, $event->account_id);

        $soldBefore = (int) DB::table('product_prices')->where('id', $productPrice->id)->value('quantity_sold');

        // The gap: the ADMIN revokes the assignment after the action's guard,
        // before the handler transaction.
        DB::table('event_box_office_operators')
            ->where(EventBoxOfficeOperatorDomainObjectAbstract::EVENT_ID, $event->id)
            ->where(EventBoxOfficeOperatorDomainObjectAbstract::USER_ID, $operator->id)
            ->update([EventBoxOfficeOperatorDomainObjectAbstract::STATUS => 'REVOKED']);

        $threw = false;
        try {
            app(CreateBoxOfficeSaleHandler::class)->handle(
                $this->makeDto($event->id, $operator->id, $product->id, $productPrice->id),
                $agent,
            );
        } catch (UnauthorizedException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the handler must reject a sale whose assignment was revoked mid-flight');
        $this->assertSame(0, DB::table('box_office_sales')->where('event_id', $event->id)->count());
        $this->assertSame(0, DB::table('orders')->where('event_id', $event->id)->count());
        $this->assertSame(
            $soldBefore,
            (int) DB::table('product_prices')->where('id', $productPrice->id)->value('quantity_sold'),
            'quantity_sold must be untouched',
        );
    }

    /**
     * Positive control: with the assignment still ACTIVE, the in-transaction
     * re-check does not break the happy path.
     */
    public function test_active_assignment_still_completes_the_sale(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $agent = $this->hydrateUserForAccount($operator, $event->account_id);

        $result = app(CreateBoxOfficeSaleHandler::class)->handle(
            $this->makeDto($event->id, $operator->id, $product->id, $productPrice->id),
            $agent,
        );

        $this->assertNotNull($result->attendee->getPublicId());
        $this->assertSame(1, DB::table('box_office_sales')->where('event_id', $event->id)->where('status', 'COMPLETED')->count());
        $this->assertSame(1, (int) DB::table('product_prices')->where('id', $productPrice->id)->value('quantity_sold'));
    }

    /**
     * A null $agent (direct callers / slice 1 tests) skips the re-check —
     * behaviour is unchanged for them.
     */
    public function test_null_agent_skips_the_recheck(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $result = app(CreateBoxOfficeSaleHandler::class)->handle(
            $this->makeDto($event->id, $user->id, $product->id, $productPrice->id),
        );

        $this->assertSame(1, DB::table('box_office_sales')->where('event_id', $event->id)->where('status', 'COMPLETED')->count());
    }
}
