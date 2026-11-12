<?php

namespace Tests\Unit\Console\Assistant;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\AssistantActionDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\EmailAlreadyExists;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\CreateManagedOrganizationHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateManagedOrganizationDTO;
use HiEvents\Services\Application\Handlers\Account\DTO\ManagedOrganizationDTO;
use HiEvents\Services\Application\Handlers\CheckInList\CreateCheckInListHandler;
use HiEvents\Services\Application\Handlers\CheckInList\DTO\UpsertCheckInListDTO;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\CreateEventImageHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\QrLink\CreateQrLinkHandler;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\Assistant\AssistantActionJournal;
use HiEvents\Services\Domain\Assistant\DTO\AssistantActionContextDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantConfirmationRequestDTO;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AssistantCreationCommandsTest extends TestCase
{
    private MockInterface $journal;

    private MockInterface $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journal = Mockery::mock(AssistantActionJournal::class);
        $this->app->instance(AssistantActionJournal::class, $this->journal);

        $organizers = Mockery::mock(OrganizerRepositoryInterface::class);
        $organizers->shouldReceive('findFirstWhere')->with(['id' => 18])->andReturn(
            (new OrganizerDomainObject)->setId(18)->setAccountId(14)->setName('CCI Mayotte')->setCurrency('EUR')
        );
        $this->app->instance(OrganizerRepositoryInterface::class, $organizers);

        $events = Mockery::mock(EventRepositoryInterface::class);
        $events->shouldReceive('findFirstWhere')->with(['id' => 25])->andReturn(
            (new EventDomainObject)->setId(25)->setOrganizerId(18)->setAccountId(14)->setTitle("Foire de l'Industrie")
                ->setStatus(EventStatus::DRAFT->name)->setCurrency('EUR')->setTimezone('Indian/Mayotte')
        );
        $this->app->instance(EventRepositoryInterface::class, $events);

        $this->products = Mockery::mock(ProductRepositoryInterface::class);
        $this->app->instance(ProductRepositoryInterface::class, $this->products);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function callCommand(string $command, array $options): array
    {
        $exitCode = Artisan::call($command, $options);
        $lines = array_values(array_filter(
            explode("\n", trim(Artisan::output())),
            fn (string $line) => str_starts_with($line, '{'),
        ));

        return [$exitCode, json_decode(end($lines), true)];
    }

    private function confirmed(int $id, string $command, ?array $params = null): void
    {
        $this->journal->shouldReceive('consumeConfirmation')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $token) => $token === 'JETON234'
                && $context->command === $command
                && ($params === null || $context->params === $params))
            ->andReturn((new AssistantActionDomainObject)->setId($id)->setSummary('Résumé'));
    }

    public function test_creating_an_organization_needs_no_organizer_and_waits_for_jo(): void
    {
        $handler = Mockery::mock(CreateManagedOrganizationHandler::class);
        $handler->shouldReceive('ensureEmailIsFree')->once()->with('contact@cci.yt');
        $handler->shouldNotReceive('handle');
        $this->app->instance(CreateManagedOrganizationHandler::class, $handler);

        $this->journal->shouldReceive('requestConfirmation')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $summary) => $context->command === 'anicka:creer-organisation'
                && $context->level === 2
                && $context->organizerId === null
                && $context->accountId === null
                && $context->params['nom'] === 'CCI Mayotte'
                && str_contains($summary, 'CCI Mayotte') && str_contains($summary, 'Catherine Mkadara'))
            ->andReturn(new AssistantConfirmationRequestDTO(actionId: 70, token: 'JETON234', expiresAt: '2026-11-12T18:00:00+00:00'));

        [$exitCode, $json] = $this->callCommand('anicka:creer-organisation', [
            '--nom' => 'CCI Mayotte', '--email' => 'contact@cci.yt', '--prenom' => 'Catherine', '--nom-contact' => 'Mkadara',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('confirmation_requise', $json['statut']);
        $this->assertSame('JETON234', $json['jeton']);
    }

    public function test_an_email_that_already_owns_an_account_is_refused(): void
    {
        $handler = Mockery::mock(CreateManagedOrganizationHandler::class);
        $handler->shouldReceive('ensureEmailIsFree')->andThrow(new EmailAlreadyExists('taken'));
        $this->app->instance(CreateManagedOrganizationHandler::class, $handler);

        $this->journal->shouldReceive('refuse')->once()->andReturn((new AssistantActionDomainObject)->setId(71));
        $this->journal->shouldNotReceive('requestConfirmation');

        [$exitCode, $json] = $this->callCommand('anicka:creer-organisation', [
            '--nom' => 'CCI Mayotte', '--email' => 'contact@cci.yt', '--prenom' => 'Catherine',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('refuse', $json['statut']);
        $this->assertStringContainsString('contact@cci.yt', $json['erreur']);
    }

    public function test_a_confirmed_organization_is_created_in_mayotte_time_and_euros(): void
    {
        $handler = Mockery::mock(CreateManagedOrganizationHandler::class);
        $handler->shouldReceive('ensureEmailIsFree');
        $handler->shouldReceive('handle')->once()
            ->withArgs(fn (CreateManagedOrganizationDTO $dto) => $dto->organizer_name === 'CCI Mayotte'
                && $dto->email === 'contact@cci.yt'
                && $dto->phone === '0639000000'
                && $dto->timezone === 'Indian/Mayotte'
                && $dto->currency === 'EUR'
                && $dto->locale === 'fr')
            ->andReturn(new ManagedOrganizationDTO(
                account: (new AccountDomainObject)->setId(14),
                user: (new UserDomainObject)->setId(30),
                organizer: (new OrganizerDomainObject)->setId(18),
            ));
        $this->app->instance(CreateManagedOrganizationHandler::class, $handler);

        $this->confirmed(70, 'anicka:creer-organisation');
        $this->journal->shouldReceive('markExecuted')->once()->with(70, ['organisateur' => 18, 'compte' => 14, 'utilisateur' => 30]);

        [$exitCode, $json] = $this->callCommand('anicka:creer-organisation', [
            '--nom' => 'CCI Mayotte', '--email' => 'contact@cci.yt', '--prenom' => 'Catherine',
            '--telephone' => '0639000000', '--confirme' => 'JETON234',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('execute', $json['statut']);
        $this->assertSame(18, $json['resultat']['organisateur']);
    }

    public function test_an_event_ending_before_it_starts_is_refused(): void
    {
        $this->journal->shouldReceive('refuse')->once()->andReturn((new AssistantActionDomainObject)->setId(72));

        [$exitCode, $json] = $this->callCommand('anicka:creer-evenement', [
            '--organisateur' => 18, '--titre' => 'Foire', '--debut' => '2026-11-21 18:00', '--fin' => '2026-11-21 08:00',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('refuse', $json['statut']);
    }

    public function test_a_cover_outside_the_assistant_files_is_refused(): void
    {
        $this->journal->shouldReceive('refuse')->once()->andReturn((new AssistantActionDomainObject)->setId(73));

        [$exitCode, $json] = $this->callCommand('anicka:creer-evenement', [
            '--organisateur' => 18, '--titre' => 'Foire', '--debut' => '2026-11-21 08:00', '--couverture' => '/etc/passwd',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('/etc/passwd', $json['erreur']);
    }

    public function test_a_confirmed_event_is_a_draft_in_mayotte_time_with_the_fee_paid_by_the_buyer(): void
    {
        $owners = Mockery::mock(AccountUserRepositoryInterface::class);
        $owners->shouldReceive('findFirstWhere')->with(['account_id' => 14, 'is_account_owner' => true])
            ->andReturn((new AccountUserDomainObject)->setUserId(30));
        $this->app->instance(AccountUserRepositoryInterface::class, $owners);

        $handler = Mockery::mock(CreateEventHandler::class);
        $handler->shouldReceive('handle')->once()
            ->withArgs(fn (CreateEventDTO $dto) => $dto->title === "Foire de l'Industrie"
                && $dto->organizer_id === 18 && $dto->account_id === 14 && $dto->user_id === 30
                && $dto->start_date === '2026-11-21 08:00' && $dto->end_date === '2026-11-21 18:00'
                && $dto->timezone === 'Indian/Mayotte'
                && $dto->status === EventStatus::DRAFT->name
                && $dto->location_details?->venue_name === 'Pôle culturel'
                && $dto->description === '<p>Venez nombreux.</p><p>Entrée libre.</p>')
            ->andReturn((new EventDomainObject)->setId(25)->setTitle("Foire de l'Industrie"));
        $this->app->instance(CreateEventHandler::class, $handler);

        $settings = Mockery::mock(EventSettingsRepositoryInterface::class);
        $settings->shouldReceive('updateWhere')->once()
            ->withArgs(fn (array $attributes, array $where) => $attributes['pass_platform_fee_to_buyer'] === true
                && $attributes['location_details']['venue_name'] === 'Pôle culturel'
                && $where === ['event_id' => 25])
            ->andReturn(1);
        $this->app->instance(EventSettingsRepositoryInterface::class, $settings);

        $images = Mockery::mock(CreateEventImageHandler::class);
        $images->shouldNotReceive('handle');
        $this->app->instance(CreateEventImageHandler::class, $images);

        $this->confirmed(74, 'anicka:creer-evenement');
        $this->journal->shouldReceive('markExecuted')->once()
            ->withArgs(fn (int $id, array $result) => $id === 74 && $result['evenement'] === 25 && $result['statut'] === 'DRAFT'
                && str_contains($result['apercu'], '/event/25/foire-de-lindustrie'));

        [$exitCode, $json] = $this->callCommand('anicka:creer-evenement', [
            '--organisateur' => 18, '--titre' => "Foire de l'Industrie", '--debut' => '2026-11-21 08:00', '--fin' => '2026-11-21 18:00',
            '--lieu' => 'Pôle culturel', '--adresse' => 'Chirongui', '--description' => "Venez nombreux.\n\nEntrée libre.",
            '--confirme' => 'JETON234',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('execute', $json['statut']);
    }

    private function category(): void
    {
        $categories = Mockery::mock(ProductCategoryRepositoryInterface::class);
        $categories->shouldReceive('findWhere')->with(['event_id' => 25])
            ->andReturn(collect([(new ProductCategoryDomainObject)->setId(40)->setOrder(1)]));
        $this->app->instance(ProductCategoryRepositoryInterface::class, $categories);
    }

    public function test_a_ticket_at_zero_is_free_and_never_a_cancellation_protection(): void
    {
        $this->category();
        $this->products->shouldReceive('countWhere')->with(['event_id' => 25])->andReturn(0);

        $handler = Mockery::mock(CreateProductHandler::class);
        $handler->shouldReceive('handle')->once()
            ->withArgs(fn (UpsertProductDTO $dto) => $dto->type === ProductPriceType::FREE
                && $dto->product_type === ProductType::TICKET
                && $dto->product_category_id === 40
                && $dto->is_refund_protection === false
                && $dto->prices->first()->price === 0.0
                && $dto->prices->first()->initial_quantity_available === 500)
            ->andReturn((new ProductDomainObject)->setId(60));
        $this->app->instance(CreateProductHandler::class, $handler);

        $this->confirmed(75, 'anicka:creer-tarif', ['nom' => 'Entrée visiteur', 'prix' => '0', 'quantite' => '500', 'organisateur' => 18, 'evenement' => 25]);
        $this->journal->shouldReceive('markExecuted')->once()
            ->with(75, ['tarif' => 60, 'type' => 'FREE', 'prix' => 0.0, 'quantite' => 500]);

        [$exitCode, $json] = $this->callCommand('anicka:creer-tarif', [
            '--organisateur' => 18, '--evenement' => 25, '--nom' => 'Entrée visiteur', '--prix' => '0', '--quantite' => '500',
            '--confirme' => 'JETON234',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(60, $json['resultat']['tarif']);
    }

    public function test_a_paid_ticket_summary_says_the_fee_is_paid_by_the_buyer(): void
    {
        $this->journal->shouldReceive('requestConfirmation')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $summary) => str_contains($summary, '12,50 EUR')
                && str_contains($summary, 'acheteur'))
            ->andReturn(new AssistantConfirmationRequestDTO(actionId: 76, token: 'JETON234', expiresAt: '2026-11-12T18:00:00+00:00'));

        [$exitCode, $json] = $this->callCommand('anicka:creer-tarif', [
            '--organisateur' => 18, '--evenement' => 25, '--nom' => 'Exposant', '--prix' => '12,50',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('confirmation_requise', $json['statut']);
    }

    public function test_a_choice_question_needs_two_answers(): void
    {
        $this->journal->shouldReceive('refuse')->once()->andReturn((new AssistantActionDomainObject)->setId(77));

        [$exitCode, $json] = $this->callCommand('anicka:ajouter-question', [
            '--organisateur' => 18, '--evenement' => 25, '--libelle' => 'Secteur', '--type' => 'choix', '--options' => 'BTP',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('|', $json['erreur']);
    }

    public function test_a_confirmed_choice_question_is_asked_once_per_order(): void
    {
        $handler = Mockery::mock(CreateQuestionHandler::class);
        $handler->shouldReceive('handle')->once()
            ->withArgs(fn (UpsertQuestionDTO $dto) => $dto->title === 'Secteur'
                && $dto->type === QuestionTypeEnum::RADIO
                && $dto->options === ['BTP', 'Commerce', 'Services']
                && $dto->required === false
                && $dto->belongs_to === QuestionBelongsTo::ORDER
                && $dto->event_id === 25)
            ->andReturn((new QuestionDomainObject)->setId(80));
        $this->app->instance(CreateQuestionHandler::class, $handler);

        $this->confirmed(78, 'anicka:ajouter-question');
        $this->journal->shouldReceive('markExecuted')->once()->with(78, ['question' => 80, 'type' => 'RADIO']);

        [$exitCode] = $this->callCommand('anicka:ajouter-question', [
            '--organisateur' => 18, '--evenement' => 25, '--libelle' => 'Secteur', '--type' => 'choix',
            '--options' => 'BTP | Commerce|Services', '--obligatoire' => 'non', '--confirme' => 'JETON234',
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_scan_access_needs_tickets_first(): void
    {
        $this->products->shouldReceive('findWhere')->andReturn(collect());
        $this->journal->shouldReceive('refuse')->once()->andReturn((new AssistantActionDomainObject)->setId(79));

        [$exitCode, $json] = $this->callCommand('anicka:creer-acces-scan', [
            '--organisateur' => 18, '--evenement' => 25, '--noms' => 'Accueil 1',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('refuse', $json['statut']);
    }

    public function test_each_scan_access_gets_its_list_and_short_link(): void
    {
        config(['app.frontend_url' => 'https://ticket.picha.fr']);

        $this->products->shouldReceive('findWhere')
            ->with(['event_id' => 25, 'product_type' => ProductType::TICKET->name])
            ->andReturn(collect([(new ProductDomainObject)->setId(60), (new ProductDomainObject)->setId(61)]));

        $lists = Mockery::mock(CreateCheckInListHandler::class);
        $lists->shouldReceive('handle')->twice()
            ->withArgs(fn (UpsertCheckInListDTO $dto) => $dto->eventId === 25 && $dto->productIds === [60, 61])
            ->andReturnUsing(fn (UpsertCheckInListDTO $dto) => (new CheckInListDomainObject)
                ->setId($dto->name === 'Accueil 1' ? 90 : 91)->setShortId($dto->name === 'Accueil 1' ? 'cil_a' : 'cil_b'));
        $this->app->instance(CreateCheckInListHandler::class, $lists);

        $links = Mockery::mock(CreateQrLinkHandler::class);
        $links->shouldReceive('handle')->twice()
            ->withArgs(fn (UpsertQrLinkDTO $dto) => $dto->destinationType === QrLinkDestinationType::URL
                && $dto->organizerId === 18
                && str_starts_with((string) $dto->destinationUrl, 'https://ticket.picha.fr/check-in/cil_'))
            ->andReturnUsing(fn (UpsertQrLinkDTO $dto) => (new QrLinkDomainObject)->setId(1)->setName($dto->name));
        $this->app->instance(CreateQrLinkHandler::class, $links);

        $urls = Mockery::mock(QrLinkUrlService::class);
        $urls->shouldReceive('getPublicUrl')->andReturn('https://ticket.picha.fr/q/abc');
        $this->app->instance(QrLinkUrlService::class, $urls);

        $this->confirmed(81, 'anicka:creer-acces-scan');
        $this->journal->shouldReceive('markExecuted')->once()
            ->withArgs(fn (int $id, array $result) => $id === 81 && count($result['acces']) === 2
                && $result['acces'][0] === ['nom' => 'Accueil 1', 'liste' => 90, 'lien' => 'https://ticket.picha.fr/q/abc']);

        [$exitCode] = $this->callCommand('anicka:creer-acces-scan', [
            '--organisateur' => 18, '--evenement' => 25, '--noms' => 'Accueil 1|Accueil 2', '--confirme' => 'JETON234',
        ]);

        $this->assertSame(0, $exitCode);
    }
}
