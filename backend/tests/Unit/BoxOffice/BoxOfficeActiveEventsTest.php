<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\BoxOfficeEventNotActiveException;
use HiEvents\Models\Event;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeContextHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeActiveEventsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    /**
     * @return int[]
     */
    private function contextEventIds(int $userId, string $role, int $accountId): array
    {
        return app(GetBoxOfficeContextHandler::class)
            ->handle($userId, $role, $accountId)
            ->map(fn ($event) => $event->id)
            ->values()
            ->all();
    }

    public function test_admin_context_only_lists_live_events_that_have_not_ended(): void
    {
        [$live, , , $user] = $this->createEventWithProduct();
        [$draft] = $this->createEventWithProductOnAccount($live->account_id);
        [$ended] = $this->createEventWithProductOnAccount($live->account_id);
        [$liveFuture] = $this->createEventWithProductOnAccount($live->account_id);

        Event::whereKey($draft->id)->update(['status' => 'DRAFT']);
        Event::whereKey($ended->id)->update(['status' => 'LIVE', 'start_date' => now()->subDays(3), 'end_date' => now()->subDay()]);
        Event::whereKey($liveFuture->id)->update(['status' => 'LIVE', 'start_date' => now()->addDay(), 'end_date' => now()->addDays(2)]);

        $ids = $this->contextEventIds($user->id, Role::ADMIN->name, $live->account_id);

        self::assertContains($live->id, $ids);
        self::assertContains($liveFuture->id, $ids);
        self::assertNotContains($draft->id, $ids);
        self::assertNotContains($ended->id, $ids);
    }

    public function test_operator_context_hides_an_assigned_event_that_is_not_live(): void
    {
        [$live] = $this->createEventWithProduct();
        [$draft] = $this->createEventWithProductOnAccount($live->account_id);
        Event::whereKey($draft->id)->update(['status' => 'DRAFT']);

        $operator = $this->makeBoxOfficeOperator($live, 'secret-password');
        $this->assignOperatorToEvent($operator, $draft);

        $ids = $this->contextEventIds($operator->id, Role::BOX_OFFICE_OPERATOR->name, $live->account_id);

        self::assertSame([$live->id], $ids);
    }

    public function test_sale_on_a_draft_event_is_refused_and_nothing_is_created(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        Event::whereKey($event->id)->update(['status' => 'DRAFT']);

        try {
            app(CreateBoxOfficeSaleHandler::class)->handle(new CreateBoxOfficeSaleDTO(
                event_id: $event->id,
                agent_user_id: $user->id,
                product_id: $product->id,
                product_price_id: $productPrice->id,
                phone: '',
                first_name: 'Test',
                last_name: '',
                email: '',
                locale: 'fr',
                amount: 25.00,
                payment_method: BoxOfficePaymentMethod::CASH,
                amount_collected: 25.00,
                idempotency_key: Str::uuid()->toString(),
            ));
            self::fail('expected BoxOfficeEventNotActiveException');
        } catch (BoxOfficeEventNotActiveException) {
        }

        self::assertSame(0, DB::table('box_office_sales')->where('event_id', $event->id)->count());
        self::assertSame(0, DB::table('orders')->where('event_id', $event->id)->count());
    }
}
