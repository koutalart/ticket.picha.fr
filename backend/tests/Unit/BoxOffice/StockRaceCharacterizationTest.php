<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Exceptions\NoTicketsAvailableException;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Characterizes the stock-checking behavior of CreateAttendeeHandler under
 * sequential vs. concurrent access, on a product price with exactly 1 ticket
 * left. Documents S2 (PICHA_BOX_OFFICE_SECURITY_FINDINGS.md): no row lock
 * (`FOR UPDATE`) on the stock read, so two concurrent sales can both read
 * "1 available" before either writes back — oversell.
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
            amount_paid: 25.00,
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
     * Uses two independent database sessions (not just two sequential calls
     * on the same connection) to reproduce the actual race window in
     * ProductRepository::getQuantityRemainingForProductPrice() — a plain
     * SELECT with no FOR UPDATE. This test documents that the bug exists
     * TODAY: both "sales" read the stock as available before either writes
     * back, so both succeed and the stock ends up oversold.
     *
     * DatabaseTransactions wraps the whole test in one outer transaction, so
     * a second, genuinely independent PDO session would not see the setup
     * data (Event/Product/ProductPrice) until that transaction commits. We
     * commit it explicitly for this test, then clean up manually in
     * `finally` — see the comment above the commit call.
     */
    public function test_two_concurrent_sales_on_last_ticket_currently_oversell(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 1,
            quantitySold: 0,
        );

        // Commit the outer DatabaseTransactions transaction so a second,
        // independent PDO connection can actually see this data. Laravel's
        // Connection::commit() decrements its own transaction-level counter
        // to 0; the trait's teardown rollBack() then computes toLevel = -1
        // and no-ops (see Illuminate\Database\Connection::rollBack()), so
        // this does not error at teardown. We are, however, now responsible
        // for cleaning up everything ourselves — see the `finally` block.
        DB::commit();

        try {
            $config = config('database.connections.pgsql');
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
            $connB = new PDO($dsn, $config['username'], $config['password']);

            $remainingSql = <<<'SQL'
                SELECT COALESCE(initial_quantity_available, 0) - quantity_sold AS remaining
                FROM product_prices
                WHERE id = ?
            SQL;

            // "Sale A" (Laravel's default connection, now autocommitting)
            // and "Sale B" (a fresh, independent session) both check stock
            // BEFORE either one has written anything back.
            $remainingA = (int) DB::selectOne($remainingSql, [$productPrice->id])->remaining;

            $stmtB = $connB->prepare($remainingSql);
            $stmtB->execute([$productPrice->id]);
            $remainingB = (int) $stmtB->fetchColumn();

            self::assertSame(1, $remainingA, 'sale A sees 1 ticket available');
            self::assertSame(1, $remainingB, 'sale B ALSO sees 1 ticket available — the race window that causes S2');

            // Both proceed, because both saw stock available. This mirrors
            // ProductQuantityUpdateService::increaseQuantitySold(): an
            // unconditional `UPDATE ... SET quantity_sold = quantity_sold + 1`
            // with no check against the value that was read a moment earlier.
            DB::update('UPDATE product_prices SET quantity_sold = quantity_sold + 1 WHERE id = ?', [$productPrice->id]);
            $connB->prepare('UPDATE product_prices SET quantity_sold = quantity_sold + 1 WHERE id = ?')
                ->execute([$productPrice->id]);

            $finalSold = (int) DB::selectOne(
                'SELECT quantity_sold FROM product_prices WHERE id = ?',
                [$productPrice->id]
            )->quantity_sold;

            // This asserts the DESIRED behavior — stock must never exceed
            // what was available — and is expected to be RED today: no row
            // lock (FOR UPDATE) guards the read, so both concurrent "sales"
            // succeed and the stock ends up oversold (2 sold on a stock of
            // 1). The failure itself is the proof of S2.
            self::assertLessThanOrEqual(
                (int) ProductPrice::find($productPrice->id)->initial_quantity_available,
                $finalSold,
                'S2: stock must not be oversold, but no row lock exists today on the stock read',
            );
        } finally {
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
