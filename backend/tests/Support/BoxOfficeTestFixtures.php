<?php

declare(strict_types=1);

namespace Tests\Support;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Account;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\CheckInList;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixture builder for characterization tests that need a real
 * Account/Organizer/Event/Product/ProductPrice graph in the database.
 *
 * There is no factory for Event/Product/ProductPrice/Organizer/CheckInList in
 * this codebase (only Account/User/AccountVatSetting/Order have factories),
 * so this trait builds the graph directly via Eloquent.
 */
trait BoxOfficeTestFixtures
{
    private function createUserWithAccount(?string $password = null): User
    {
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => [
                'percentage' => 1.5,
                'fixed' => 0,
            ],
        ]);

        $factory = User::factory()->withAccount();

        if ($password !== null) {
            $factory = $factory->password($password);
        }

        return $factory->create();
    }

    /**
     * Logs a user in via /auth/login and returns the X-Auth-Token header
     * value, for Feature tests hitting authenticated routes.
     */
    private function loginAndGetToken(User $user, string $password): string
    {
        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        return $response->headers->get('X-Auth-Token');
    }

    /**
     * @return array{0: Event, 1: Product, 2: ProductPrice, 3: User}
     */
    private function createEventWithProduct(
        float   $price = 25.00,
        ?int    $initialQuantityAvailable = 100,
        int     $quantitySold = 0,
        ?string $userPassword = null,
    ): array
    {
        $user = $this->createUserWithAccount($userPassword);

        /** @var Account $account */
        $account = $user->accounts()->first();

        // Event::boot() reads auth()->user()->id unconditionally on creating().
        Auth::login($user);

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Test Organizer',
            'email' => 'organizer@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $event = Event::create([
            'title' => 'Test Event',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'currency' => 'USD',
            'status' => 'LIVE',
            'short_id' => IdHelper::shortId(IdHelper::EVENT_PREFIX),
        ]);

        // Not auto-created outside CreateEventService — CreateAttendeeCheckInService
        // expects a row to exist (non-nullable EventSettingDomainObject return type).
        EventSetting::create([
            'event_id' => $event->id,
            'allow_orders_awaiting_offline_payment_to_check_in' => true,
        ]);

        $product = Product::create([
            'event_id' => $event->id,
            'title' => 'GA Ticket',
            'product_type' => ProductType::TICKET->name,
            'type' => 'PAID',
            'order' => 1,
        ]);

        $productPrice = ProductPrice::create([
            'product_id' => $product->id,
            'price' => $price,
            'initial_quantity_available' => $initialQuantityAvailable,
            'quantity_sold' => $quantitySold,
            'order' => 1,
        ]);

        return [$event, $product, $productPrice, $user];
    }

    /**
     * A second Event/Product/ProductPrice graph on an EXISTING account
     * (createEventWithProduct always spins a fresh account). Needed to test
     * per-event scoping *within one account* — the interesting case that a
     * cross-account graph would mask.
     *
     * @return array{0: Event, 1: Product, 2: ProductPrice}
     */
    private function createEventWithProductOnAccount(int $accountId, float $price = 25.00): array
    {
        $ownerId = DB::table('account_users')
            ->where('account_id', $accountId)
            ->where('is_account_owner', true)
            ->value('user_id');

        // Event::boot() reads auth()->user()->id unconditionally on creating().
        Auth::login(User::findOrFail($ownerId));

        $organizer = Organizer::create([
            'account_id' => $accountId,
            'name' => 'Test Organizer 2',
            'email' => 'organizer2@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $event = Event::create([
            'title' => 'Second Test Event',
            'account_id' => $accountId,
            'organizer_id' => $organizer->id,
            'currency' => 'USD',
            'status' => 'LIVE',
            'short_id' => IdHelper::shortId(IdHelper::EVENT_PREFIX),
        ]);

        EventSetting::create([
            'event_id' => $event->id,
            'allow_orders_awaiting_offline_payment_to_check_in' => true,
        ]);

        $product = Product::create([
            'event_id' => $event->id,
            'title' => 'GA Ticket 2',
            'product_type' => ProductType::TICKET->name,
            'type' => 'PAID',
            'order' => 1,
        ]);

        $productPrice = ProductPrice::create([
            'product_id' => $product->id,
            'price' => $price,
            'initial_quantity_available' => 100,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        return [$event, $product, $productPrice];
    }

    private function attachCheckInList(Event $event, Product $product): CheckInList
    {
        $checkInList = CheckInList::create([
            'event_id' => $event->id,
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_LIST_PREFIX),
            'name' => 'Main Entrance',
        ]);

        $checkInList->products()->attach($product->id);

        return $checkInList;
    }

    /**
     * Creates an attendee through the existing, unmodified manual-sale path
     * (CreateAttendeeHandler) — what the Box Office slice 1 (Option A)
     * intends to reuse under CreateBoxOfficeSaleHandler.
     */
    private function createAttendeeViaHandler(int $eventId, int $productId, int $productPriceId): \HiEvents\Models\Attendee
    {
        $handler = app(\HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler::class);

        $domainAttendee = $handler->handle(new \HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $productId,
            event_id: $eventId,
            send_confirmation_email: false,
            amount_paid: 25.00,
            locale: 'en',
            product_price_id: $productPriceId,
        ));

        return \HiEvents\Models\Attendee::find($domainAttendee->getId());
    }

    /**
     * A second Account/User, unrelated to the event's owning account — for
     * asserting cross-account authorization is enforced (403).
     */
    private function createUnrelatedOrganizerUser(string $password): User
    {
        return $this->createUserWithAccount($password);
    }

    // ---------------------------------------------------------------------
    // Kiosk v2 — operator account fixtures (D23 Option 1).
    // event_box_office_operators and the Role::BOX_OFFICE_OPERATOR enum case
    // do not exist yet — helpers that touch them will error until the
    // slice v2.1 migration/enum land. This is the intended TDD-red state.
    // ---------------------------------------------------------------------

    /**
     * Attach a brand-new user to the EVENT's owning account with the given
     * role string (varchar column, so an as-yet-unknown enum value is fine).
     */
    private function attachUserToEventAccount(Event $event, string $role, string $password): User
    {
        $user = User::factory()->password($password)->create();

        $user->accounts()->attach($event->account_id, [
            'role' => $role,
            'status' => UserStatus::ACTIVE->name,
            'is_account_owner' => false,
        ]);

        return $user;
    }

    /**
     * A real ORGANIZER on the event's account (distinct from the ADMIN
     * account owner created by createEventWithProduct()).
     */
    private function makeOrganizerOnEvent(Event $event, string $password): User
    {
        return $this->attachUserToEventAccount($event, Role::ORGANIZER->name, $password);
    }

    /**
     * A BOX_OFFICE_OPERATOR: account_users row (role BOX_OFFICE_OPERATOR,
     * ACTIVE) + one event_box_office_operators row for $event.
     */
    private function makeBoxOfficeOperator(Event $event, string $password, string $status = 'ACTIVE'): User
    {
        $operator = $this->attachUserToEventAccount($event, 'BOX_OFFICE_OPERATOR', $password);
        $this->assignOperatorToEvent($operator, $event, $status);

        return $operator;
    }

    /**
     * Add a single event_box_office_operators row (used for multi-event
     * assignment and revocation scenarios).
     */
    private function assignOperatorToEvent(User $operator, Event $event, string $status = 'ACTIVE', ?int $createdByUserId = null): void
    {
        DB::table('event_box_office_operators')->insert([
            'event_id' => $event->id,
            'user_id' => $operator->id,
            'created_by_user_id' => $createdByUserId ?? $operator->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Hydrate a UserDomainObject with its current-account AccountUser set,
     * exactly as AuthUserService::getUser() does — so IsAuthorizedService
     * can be exercised directly without going through the login flow (which
     * would blow up on Role::from('BOX_OFFICE_OPERATOR') until the enum case
     * exists).
     */
    private function hydrateUserForAccount(User $user, int $accountId): UserDomainObject
    {
        $domainUser = UserDomainObject::hydrateFromModel($user);

        $domainUser->setCurrentAccountUser(
            app(AccountUserRepositoryInterface::class)->findFirstWhere([
                'user_id' => $user->id,
                'account_id' => $accountId,
            ])
        );

        return $domainUser;
    }
}
