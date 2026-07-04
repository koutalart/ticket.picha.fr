<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\ProductStatus;
use HiEvents\Services\Application\Handlers\Account\CreateAccountHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateAccountDTO;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Application\Handlers\CheckInList\CreateCheckInListHandler;
use HiEvents\Services\Application\Handlers\CheckInList\DTO\UpsertCheckInListDTO;
use HiEvents\Services\Application\Handlers\CheckInList\Public\CreateAttendeeCheckInPublicHandler;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\CreateAttendeeCheckInPublicDTO;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Application\Handlers\ProductCategory\CreateProductCategoryHandler;
use HiEvents\Services\Application\Handlers\ProductCategory\DTO\UpsertProductCategoryDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use HiEvents\Models\User as UserModel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * DIGIT Ticket — permanent staging demo environment seeder.
 *
 * Creates (idempotently, safe to re-run):
 *   - a demo Account + admin User
 *   - Organizer "DIGIT Demo"
 *   - Event "Festival Demo 2026"
 *   - ProductCategory "Billets" with 3 Products: VIP, Grand Public, Staff
 *   - CheckInList "Entrée principale"
 *   - ~50 Attendees spread across the 3 ticket types
 *   - a subset of attendees pre-marked as already checked in (for duplicate-scan tests)
 *
 * This command ONLY uses Hi.Events' own core Handlers/DTOs. No core file is
 * modified, no raw Eloquent inserts are used for domain data.
 *
 * Usage:
 *   php artisan digit:demo:seed
 *   php artisan digit:demo:seed --password="SomeStrongDemoPass123!"
 *   php artisan digit:demo:seed --fresh   (wipes and recreates the demo account)
 */
class DigitDemoSeedCommand extends Command
{
    protected $signature = 'digit:demo:seed
        {--email=demo@ticket.picha.fr : Demo account email}
        {--password= : Demo account password (prompted if omitted)}
        {--attendees=50 : Number of attendees to generate}
        {--checked-in=8 : Number of attendees to pre-mark as already checked in}
        {--fresh : Delete any existing demo account with this email before seeding}';

    protected $description = 'Seed a permanent DIGIT Ticket demo environment (organizer, event, tickets, attendees, check-ins)';

    private array $summary = [];

    public function handle(
        CreateAccountHandler                $createAccountHandler,
        CreateOrganizerHandler              $createOrganizerHandler,
        CreateEventHandler                  $createEventHandler,
        CreateProductCategoryHandler        $createProductCategoryHandler,
        CreateProductHandler                $createProductHandler,
        CreateCheckInListHandler            $createCheckInListHandler,
        CreateAttendeeHandler               $createAttendeeHandler,
        CreateAttendeeCheckInPublicHandler  $createAttendeeCheckInPublicHandler,
    ): int
    {
        $email = strtolower((string) $this->option('email'));
        $password = $this->option('password') ?: $this->secret('Demo account password (input hidden)') ?: Str::password(20);
        $attendeeCount = max(1, (int) $this->option('attendees'));
        $checkedInCount = max(0, (int) $this->option('checked-in'));

        try {
            $this->ensureDefaultAccountConfiguration();

            if ($this->option('fresh')) {
                $this->wipeExistingDemoAccount($email);
            }

            $existingUser = DB::table('users')->where('email', $email)->first();

            if ($existingUser !== null) {
                $this->warn("Un compte demo existe déjà pour {$email}. Réutilisation (utilisez --fresh pour repartir de zéro).");
                $account = $this->reuseExistingAccount($existingUser);
            } else {
                $account = $createAccountHandler->handle(CreateAccountDTO::fromArray([
                    'email' => $email,
                    'password' => $password,
                    'first_name' => 'DIGIT',
                    'last_name' => 'Demo',
                    'locale' => 'en',
                    'timezone' => 'Indian/Mayotte',
                    'currency_code' => 'EUR',
                ]));
                $this->summary['account_password'] = $password;
            }

            $accountId = $account->getId();
            $userId = (int) DB::table('users')->where('email', $email)->value('id');

            // Event::boot() reads auth()->user()->id when creating an Event.
            // There is no authenticated HTTP request in a console context, so we
            // resolve the demo user on the default guard for the rest of this command.
            Auth::setUser(UserModel::findOrFail($userId));

            $this->summary['account_id'] = $accountId;
            $this->summary['account_email'] = $email;

            $organizerRow = DB::table('organizers')
                ->where('account_id', $accountId)
                ->where('name', 'DIGIT Demo')
                ->first();

            if ($organizerRow === null) {
                $organizer = $createOrganizerHandler->handle(CreateOrganizerDTO::fromArray([
                    'name' => 'DIGIT Demo',
                    'email' => $email,
                    'account_id' => $accountId,
                    'timezone' => 'Indian/Mayotte',
                    'currency' => 'EUR',
                ]));
                $organizerId = $organizer->getId();
            } else {
                $organizerId = $organizerRow->id;
            }

            $this->summary['organizer_id'] = $organizerId;

            $eventRow = DB::table('events')
                ->where('account_id', $accountId)
                ->where('title', 'Festival Demo 2026')
                ->first();

            if ($eventRow === null) {
                $event = $createEventHandler->handle(CreateEventDTO::fromArray([
                    'title' => 'Festival Demo 2026',
                    'organizer_id' => $organizerId,
                    'account_id' => $accountId,
                    'user_id' => $userId,
                    'start_date' => now()->addMonths(2)->setTime(18, 0)->toDateTimeString(),
                    'end_date' => now()->addMonths(2)->addDay()->setTime(2, 0)->toDateTimeString(),
                    'description' => 'Environnement de démonstration permanent DIGIT Ticket — ne pas supprimer.',
                    'timezone' => 'Indian/Mayotte',
                    'currency' => 'EUR',
                    'status' => EventStatus::LIVE->name,
                ]));
                $eventId = $event->getId();
                $eventShortId = $event->getShortId();
            } else {
                $eventId = $eventRow->id;
                $eventShortId = $eventRow->short_id;
                DB::table('events')->where('id', $eventId)->update(['status' => EventStatus::LIVE->name]);
            }

            $this->summary['event_id'] = $eventId;
            $this->summary['event_short_id'] = $eventShortId;

            $categoryRow = DB::table('product_categories')
                ->where('event_id', $eventId)
                ->where('name', 'Billets')
                ->first();

            if ($categoryRow === null) {
                $category = $createProductCategoryHandler->handle(new UpsertProductCategoryDTO(
                    name: 'Billets',
                    description: 'Catégories de billets — démo',
                    is_hidden: false,
                    event_id: $eventId,
                ));
                $categoryId = $category->getId();
            } else {
                $categoryId = $categoryRow->id;
            }

            $this->summary['product_category_id'] = $categoryId;

            $ticketTypes = [
                ['title' => 'VIP', 'price' => 150.00, 'quantity' => 15],
                ['title' => 'Grand Public', 'price' => 30.00, 'quantity' => 200],
                ['title' => 'Staff', 'price' => 0.00, 'quantity' => 50],
            ];

            $productIds = [];
            $productPriceIdByTitle = [];

            foreach ($ticketTypes as $ticketType) {
                $productRow = DB::table('products')
                    ->where('event_id', $eventId)
                    ->where('title', $ticketType['title'])
                    ->first();

                if ($productRow === null) {
                    $product = $createProductHandler->handle(new UpsertProductDTO(
                        account_id: $accountId,
                        event_id: $eventId,
                        product_category_id: $categoryId,
                        title: $ticketType['title'],
                        type: $ticketType['price'] > 0 ? ProductPriceType::PAID : ProductPriceType::FREE,
                        product_type: ProductType::TICKET,
                        prices: new Collection([
                            new ProductPriceDTO(
                                price: $ticketType['price'],
                                label: $ticketType['title'],
                                initial_quantity_available: $ticketType['quantity'],
                                status: ProductStatus::ACTIVE,
                            ),
                        ]),
                        initial_quantity_available: $ticketType['quantity'],
                    ));
                    $productId = $product->getId();
                } else {
                    $productId = $productRow->id;
                }

                $productIds[] = $productId;
                $productPriceIdByTitle[$ticketType['title']] = [
                    'product_id' => $productId,
                    'product_price_id' => (int) DB::table('product_prices')
                        ->where('product_id', $productId)
                        ->value('id'),
                ];
            }

            $this->summary['products'] = $productPriceIdByTitle;

            $checkInListRow = DB::table('check_in_lists')
                ->where('event_id', $eventId)
                ->where('name', 'Entrée principale')
                ->first();

            if ($checkInListRow === null) {
                $checkInList = $createCheckInListHandler->handle(new UpsertCheckInListDTO(
                    name: 'Entrée principale',
                    description: 'Point de contrôle principal — démo',
                    eventId: $eventId,
                    productIds: $productIds,
                ));
                $checkInListId = $checkInList->getId();
                $checkInListShortId = $checkInList->getShortId();
            } else {
                $checkInListId = $checkInListRow->id;
                $checkInListShortId = $checkInListRow->short_id;
            }

            $this->summary['check_in_list_id'] = $checkInListId;
            $this->summary['check_in_list_short_id'] = $checkInListShortId;

            $existingAttendeeCount = DB::table('attendees')->where('event_id', $eventId)->count();
            $createdAttendeePublicIds = [];

            if ($existingAttendeeCount < $attendeeCount) {
                $toCreate = $attendeeCount - $existingAttendeeCount;
                $distribution = ['VIP', 'Grand Public', 'Grand Public', 'Grand Public', 'Staff'];

                $this->info("Création de {$toCreate} attendees...");
                $bar = $this->output->createProgressBar($toCreate);

                for ($i = 0; $i < $toCreate; $i++) {
                    $ticketTitle = $distribution[$i % count($distribution)];
                    $ids = $productPriceIdByTitle[$ticketTitle];
                    $n = $existingAttendeeCount + $i + 1;

                    $attendee = $createAttendeeHandler->handle(CreateAttendeeDTO::fromArray([
                        'first_name' => 'Demo',
                        'last_name' => "Attendee {$n}",
                        'email' => "demo-attendee-{$n}@ticket.picha.fr",
                        'product_id' => $ids['product_id'],
                        'product_price_id' => $ids['product_price_id'],
                        'event_id' => $eventId,
                        'send_confirmation_email' => false,
                        'amount_paid' => 0,
                        'locale' => 'en',
                    ]));

                    $createdAttendeePublicIds[] = $attendee->getPublicId();
                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            }

            $allAttendeePublicIds = DB::table('attendees')
                ->where('event_id', $eventId)
                ->orderBy('id')
                ->pluck('public_id')
                ->all();

            $this->summary['attendee_count'] = count($allAttendeePublicIds);
            $this->summary['sample_attendee_public_ids'] = array_slice($allAttendeePublicIds, 0, 5);

            $alreadyCheckedInIds = DB::table('attendee_check_ins')
                ->where('check_in_list_id', $checkInListId)
                ->whereNull('deleted_at')
                ->pluck('attendee_id')
                ->all();

            $candidateIds = array_slice($allAttendeePublicIds, 0, $checkedInCount);
            $markedNow = [];

            if (!empty($candidateIds)) {
                $actions = new Collection(array_map(
                    fn (string $publicId) => new AttendeeAndActionDTO(
                        public_id: $publicId,
                        action: AttendeeCheckInActionType::CHECK_IN,
                    ),
                    $candidateIds
                ));

                try {
                    $result = $createAttendeeCheckInPublicHandler->handle(CreateAttendeeCheckInPublicDTO::from([
                        'checkInListUuid' => $checkInListShortId,
                        'checkInUserIpAddress' => '127.0.0.1',
                        'attendeesAndActions' => $actions,
                    ]));
                    $markedNow = $candidateIds;

                    if (!empty($result->errors->errors)) {
                        $this->warn('Certains check-ins de démo ont échoué (probablement déjà check-in) : ' . count($result->errors->errors));
                    }
                } catch (Throwable $e) {
                    $this->warn('Impossible de pré-marquer les check-ins de démo : ' . $e->getMessage());
                }
            }

            $this->summary['pre_checked_in_public_ids'] = array_values(array_unique(array_merge($alreadyCheckedInIds ? $candidateIds : [], $markedNow)));
            $this->summary['pre_checked_in_count'] = DB::table('attendee_check_ins')
                ->where('check_in_list_id', $checkInListId)
                ->whereNull('deleted_at')
                ->count();

            $this->printSummary();

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Échec du seeding : ' . $e->getMessage());
            $this->error($e->getFile() . ':' . $e->getLine());
            return self::FAILURE;
        }
    }

    private function ensureDefaultAccountConfiguration(): void
    {
        $exists = DB::table('account_configuration')->where('is_system_default', true)->exists();

        if (!$exists) {
            $this->warn('Aucune account_configuration par défaut trouvée — création...');
            DB::table('account_configuration')->insert([
                'name' => 'Default',
                'is_system_default' => true,
                'application_fees' => json_encode(['percentage' => 0, 'fixed' => 0]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function wipeExistingDemoAccount(string $email): void
    {
        $user = DB::table('users')->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $accountUser = DB::table('account_users')->where('user_id', $user->id)->first();

        if ($accountUser === null) {
            return;
        }

        $accountId = $accountUser->account_id;

        $this->warn("Suppression du compte demo existant (account_id={$accountId})...");

        DB::transaction(function () use ($accountId, $user) {
            $eventIds = DB::table('events')->where('account_id', $accountId)->pluck('id');

            foreach ($eventIds as $eventId) {
                $checkInListIds = DB::table('check_in_lists')->where('event_id', $eventId)->pluck('id');
                DB::table('attendee_check_ins')->whereIn('check_in_list_id', $checkInListIds)->delete();
                DB::table('check_in_list_product')->whereIn('check_in_list_id', $checkInListIds)->delete();
                DB::table('check_in_lists')->where('event_id', $eventId)->delete();
                DB::table('attendees')->where('event_id', $eventId)->delete();
                DB::table('product_prices')->whereIn('product_id', DB::table('products')->where('event_id', $eventId)->pluck('id'))->delete();
                DB::table('products')->where('event_id', $eventId)->delete();
                DB::table('product_categories')->where('event_id', $eventId)->delete();
            }

            DB::table('events')->where('account_id', $accountId)->delete();
            DB::table('organizers')->where('account_id', $accountId)->delete();
            DB::table('account_users')->where('account_id', $accountId)->delete();
            DB::table('accounts')->where('id', $accountId)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        });
    }

    private function reuseExistingAccount(object $existingUser): object
    {
        $accountUser = DB::table('account_users')->where('user_id', $existingUser->id)->first();
        $accountRow = DB::table('accounts')->where('id', $accountUser->account_id)->first();

        return new class($accountRow->id) {
            public function __construct(private readonly int $id) {}
            public function getId(): int { return $this->id; }
        };
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->line('<fg=green;options=bold>=== DIGIT Ticket — Environnement de démonstration ===</>');
        $this->newLine();

        $this->table(['Clé', 'Valeur'], [
            ['Account ID', $this->summary['account_id']],
            ['Compte email', $this->summary['account_email']],
            ['Mot de passe', $this->summary['account_password'] ?? '(compte déjà existant — inchangé)'],
            ['Organizer ID', $this->summary['organizer_id']],
            ['Event ID', $this->summary['event_id']],
            ['Event short_id (UUID public)', $this->summary['event_short_id']],
            ['Product category ID', $this->summary['product_category_id']],
            ['Check-in list ID', $this->summary['check_in_list_id']],
            ['Check-in list short_id (UUID public)', $this->summary['check_in_list_short_id']],
            ['Nombre total attendees', $this->summary['attendee_count']],
            ['Attendees déjà check-in (pour test doublon)', $this->summary['pre_checked_in_count']],
        ]);

        $this->newLine();
        $this->line('Produits (title => product_id / product_price_id) :');
        foreach ($this->summary['products'] as $title => $ids) {
            $this->line("  - {$title}: product_id={$ids['product_id']} / product_price_id={$ids['product_price_id']}");
        }

        $this->newLine();
        $this->line('Échantillon de public_id attendees (pour QR codes de test) :');
        foreach ($this->summary['sample_attendee_public_ids'] as $publicId) {
            $this->line("  - {$publicId}");
        }

        $this->newLine();
        $this->line('Endpoint de scan DIGIT (Module 3) :');
        $this->line("  POST /digit/scan/check-in-lists/{$this->summary['check_in_list_short_id']}/check-ins");
        $this->newLine();
    }
}
