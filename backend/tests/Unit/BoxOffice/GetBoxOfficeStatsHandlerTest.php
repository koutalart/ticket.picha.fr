<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Models\BoxOfficeSale;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeStatsHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class GetBoxOfficeStatsHandlerTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    public function test_empty_event_returns_zero_totals(): void
    {
        [$event, , , $admin] = $this->createEventWithProduct(price: 25.00);
        $user = $this->hydrateUserForAccount($admin, $event->account_id);

        $stats = app(GetBoxOfficeStatsHandler::class)->handle($event->id, $user, \HiEvents\DomainObjects\Enums\Role::ADMIN);

        self::assertSame(0, $stats->sales_count);
        self::assertSame(0, $stats->ticket_count);
        self::assertSame(0.0, $stats->total_amount);
        self::assertSame(0.0, $stats->total_collected);
        self::assertSame([], $stats->by_payment_method);
        self::assertSame([], $stats->by_agent);
    }

    public function test_operator_scope_excludes_other_agents(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $operatorA = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $operatorB = $this->makeBoxOfficeOperator($event, self::PASSWORD);

        $this->insertCompletedSale($event->id, $operatorA->id, $product->id, $productPrice->id, 25.00);
        $this->insertCompletedSale($event->id, $operatorB->id, $product->id, $productPrice->id, 40.00);

        $domainA = $this->hydrateUserForAccount($operatorA, $event->account_id);
        $stats = app(GetBoxOfficeStatsHandler::class)->handle($event->id, $domainA, \HiEvents\DomainObjects\Enums\Role::BOX_OFFICE_OPERATOR);

        self::assertSame(1, $stats->sales_count);
        self::assertSame(25.0, $stats->total_collected);
        self::assertCount(1, $stats->by_agent);
        self::assertSame($operatorA->id, $stats->by_agent[0]['agent_user_id']);
    }

    private function insertCompletedSale(int $eventId, int $agentUserId, int $productId, int $productPriceId, float $amount): void
    {
        BoxOfficeSale::query()->create([
            'idempotency_key' => Str::uuid()->toString(),
            'event_id' => $eventId,
            'agent_user_id' => $agentUserId,
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'payment_method' => 'CASH',
            'amount' => $amount,
            'amount_collected' => $amount,
            'status' => 'COMPLETED',
        ]);
    }
}
