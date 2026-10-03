<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Exceptions\NoTicketsAvailableException;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Stock checking of CreateAttendeeHandler under sequential vs. concurrent
 * access, on a product price with exactly 1 ticket left. Fixes S2: the price
 * row is locked (`FOR UPDATE`) before the stock is read, so a concurrent sale
 * cannot read a stale "1 available" and oversell.
 */
class StockRaceCharacterizationTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_two_sequential_sales_on_last_ticket_second_fails(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 1,
            quantitySold: 0,
        );

        $handler = app(CreateAttendeeHandler::class);

        $dto = fn () => new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
        );

        // First sale succeeds — takes the last ticket.
        $handler->handle($dto());

        // Second, sequential sale on the same (now sold-out) price fails.
        $this->expectException(NoTicketsAvailableException::class);

        try {
            $handler->handle($dto());
        } finally {
            self::assertSame(1, ProductPrice::find($productPrice->id)->quantity_sold, 'stock was not oversold sequentially');
        }
    }

    /**
     * Uses a second, genuinely independent database session that holds the
     * price row lock — as a concurrent sale in progress would. The handler
     * must wait for that lock instead of reading the stock right away; with a
     * short lock_timeout, waiting surfaces as a lock-timeout error (55P03).
     *
     * DatabaseTransactions wraps the whole test in one outer transaction, so
     * the second session would not see the setup data until it commits. We
     * commit it explicitly, then clean up manually in `finally`.
     */
    public function test_concurrent_sale_waits_for_the_stock_lock(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 1,
            quantitySold: 0,
        );

        // Laravel's Connection::commit() takes its transaction counter to 0;
        // the trait's teardown rollBack() then no-ops. Cleanup is ours.
        DB::commit();

        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $connB = new PDO($dsn, $config['username'], $config['password']);
        $connB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            // Sale B is in progress: it holds the row lock on the last ticket.
            $connB->beginTransaction();
            $connB->prepare('SELECT id FROM product_prices WHERE id = ? FOR UPDATE')->execute([$productPrice->id]);

            DB::statement("SET lock_timeout = '500ms'");

            $blockedStatement = null;
            try {
                app(CreateAttendeeHandler::class)->handle(new CreateAttendeeDTO(
                    first_name: 'Jane',
                    last_name: 'Doe',
                    email: 'jane@example.test',
                    product_id: $product->id,
                    event_id: $event->id,
                    send_confirmation_email: false,
                    locale: 'en',
                    product_price_id: $productPrice->id,
                ));
            } catch (QueryException $exception) {
                if ($exception->getCode() === '55P03') {
                    $blockedStatement = strtolower($exception->getSql());
                }
            }

            self::assertNotNull($blockedStatement, 'S2: sale A must wait for the lock held by sale B');
            self::assertStringContainsString(
                'for update',
                $blockedStatement,
                'S2: sale A must wait on the locked stock read, before reading a possibly stale stock',
            );
            self::assertSame(0, (int) DB::table('product_prices')->where('id', $productPrice->id)->value('quantity_sold'));
            self::assertSame(0, DB::table('orders')->where('event_id', $event->id)->count(), 'nothing is written while waiting');
        } finally {
            if ($connB->inTransaction()) {
                $connB->rollBack();
            }
            DB::statement('SET lock_timeout = 0');

            $userIds = DB::table('account_users')->where('account_id', $event->account_id)->pluck('user_id');

            DB::table('event_settings')->where('event_id', $event->id)->delete();
            DB::table('product_prices')->where('id', $productPrice->id)->delete();
            DB::table('products')->where('id', $product->id)->delete();
            DB::table('events')->where('id', $event->id)->delete();
            DB::table('organizers')->where('id', $event->organizer_id)->delete();
            DB::table('accounts')->where('id', $event->account_id)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        }
    }
}
