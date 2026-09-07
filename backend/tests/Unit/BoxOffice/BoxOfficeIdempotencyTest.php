<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) tests for box_office_sales.idempotency_key (D9,
 * PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md). box_office_sales does not exist
 * yet — every test is expected to error until the slice 1 migration and
 * CreateBoxOfficeSaleHandler land. See FIRST_SLICE §5.2.
 */
class BoxOfficeIdempotencyTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function makeDto(int $eventId, int $agentUserId, int $productId, int $productPriceId, string $idempotencyKey): CreateBoxOfficeSaleDTO
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
            idempotency_key: $idempotencyKey,
        );
    }

    /** AC-10 */
    public function test_same_key_sequential_returns_same_sale(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);
        $key = \Illuminate\Support\Str::uuid()->toString();

        $first = $handler->handle($this->makeDto($event->id, $user->id, $product->id, $productPrice->id, $key));
        $second = $handler->handle($this->makeDto($event->id, $user->id, $product->id, $productPrice->id, $key));

        self::assertSame($first->saleId, $second->saleId);
        self::assertSame($first->attendee->getPublicId(), $second->attendee->getPublicId());
        self::assertSame(1, DB::table('box_office_sales')->where('idempotency_key', $key)->count());
        self::assertSame(1, \HiEvents\Models\ProductPrice::find($productPrice->id)->quantity_sold);
    }

    /** AC-11 — two independent DB sessions racing on the same key must yield a single sale */
    public function test_same_key_concurrent_creates_single_sale(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        // Make the setup visible to a second, independent PDO connection —
        // see StockRaceCharacterizationTest for why this is necessary under
        // DatabaseTransactions.
        DB::commit();

        $key = \Illuminate\Support\Str::uuid()->toString();

        try {
            $config = config('database.connections.pgsql');
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
            $connB = new \PDO($dsn, $config['username'], $config['password']);

            // Connection A inserts the idempotency row first (autocommit).
            DB::table('box_office_sales')->insert([
                'idempotency_key' => $key,
                'event_id' => $event->id,
                'agent_user_id' => $user->id,
                'status' => 'PENDING',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Connection B attempts the exact same insert — must fail on the
            // UNIQUE constraint (D9), not silently create a second row.
            $this->expectException(\PDOException::class);

            $connB->prepare(
                'INSERT INTO box_office_sales (idempotency_key, event_id, agent_user_id, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, now(), now())'
            )->execute([$key, $event->id, $user->id, 'PENDING']);
        } finally {
            // Cleanup runs FIRST and unconditionally (this test committed
            // its setup data outside the DatabaseTransactions rollback —
            // see the comment above DB::commit() in
            // StockRaceCharacterizationTest for why). If a later assertion
            // fails, we still must not leave orphan rows in the shared dev
            // database. box_office_sales does not exist yet, so guard
            // against that specifically — it must not prevent the rest of
            // the cleanup (event/account/user) from running.
            $saleCount = null;
            try {
                $saleCount = DB::table('box_office_sales')->where('idempotency_key', $key)->count();
                DB::table('box_office_sales')->where('idempotency_key', $key)->delete();
            } catch (\Throwable) {
                // table doesn't exist yet — nothing to clean up there
            }

            $userIds = DB::table('account_users')->where('account_id', $event->account_id)->pluck('user_id');
            DB::table('event_settings')->where('event_id', $event->id)->delete();
            DB::table('product_check_in_lists')->where('product_id', $product->id)->delete();
            DB::table('check_in_lists')->where('event_id', $event->id)->delete();
            DB::table('product_prices')->where('id', $productPrice->id)->delete();
            DB::table('products')->where('id', $product->id)->delete();
            DB::table('events')->where('id', $event->id)->delete();
            DB::table('organizers')->where('id', $event->organizer_id)->delete();
            DB::table('accounts')->where('id', $event->account_id)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();

            self::assertSame(1, $saleCount);
        }
    }

    /** AC-12 — different keys are NOT deduplicated by content */
    public function test_different_keys_create_distinct_sales(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 10,
        );
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $first = $handler->handle($this->makeDto($event->id, $user->id, $product->id, $productPrice->id, \Illuminate\Support\Str::uuid()->toString()));
        $second = $handler->handle($this->makeDto($event->id, $user->id, $product->id, $productPrice->id, \Illuminate\Support\Str::uuid()->toString()));

        self::assertNotSame($first->saleId, $second->saleId);
        self::assertNotSame($first->attendee->getId(), $second->attendee->getId());
        self::assertSame(2, \HiEvents\Models\ProductPrice::find($productPrice->id)->quantity_sold);
    }
}
