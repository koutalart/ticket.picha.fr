<?php

namespace Tests\Unit\Console\Assistant;

use HiEvents\DomainObjects\AssistantActionDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\AssistantConfirmationException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Application\Handlers\Product\ChangeProductPriceHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\ChangeProductPriceDTO;
use HiEvents\Services\Application\Handlers\Product\DTO\SetProductSalesPausedDTO;
use HiEvents\Services\Application\Handlers\Product\SetProductSalesPausedHandler;
use HiEvents\Services\Application\Handlers\Sms\ScheduleSmsReminderHandler;
use HiEvents\Services\Domain\Assistant\AssistantActionJournal;
use HiEvents\Services\Domain\Assistant\DTO\AssistantActionContextDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantConfirmationRequestDTO;
use HiEvents\Services\Domain\Order\DTO\OrderOrganizerShareDTO;
use HiEvents\Services\Domain\Order\OrderOrganizerShareService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AssistantCommandsTest extends TestCase
{
    private MockInterface $journal;

    private MockInterface $organizers;

    private MockInterface $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journal = Mockery::mock(AssistantActionJournal::class);
        $this->organizers = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->events = Mockery::mock(EventRepositoryInterface::class);

        $this->app->instance(AssistantActionJournal::class, $this->journal);
        $this->app->instance(OrganizerRepositoryInterface::class, $this->organizers);
        $this->app->instance(EventRepositoryInterface::class, $this->events);

        $this->organizers->shouldReceive('findFirstWhere')->with(['id' => 4])->andReturn(
            (new OrganizerDomainObject)->setId(4)->setAccountId(3)->setName('Innocent Event')
        );
        $this->organizers->shouldReceive('findFirstWhere')->with(['id' => 9])->andReturn(
            (new OrganizerDomainObject)->setId(9)->setAccountId(5)->setName('Konka Wawa')
        );
        $this->events->shouldReceive('findFirstWhere')->with(['id' => 7])->andReturn(
            (new EventDomainObject)->setId(7)->setOrganizerId(4)->setAccountId(3)->setTitle('Halloween')
                ->setStatus(EventStatus::DRAFT->name)->setCurrency('EUR')->setTimezone('Indian/Mayotte')
        );
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

    private function action(int $id, string $summary = 'Résumé'): AssistantActionDomainObject
    {
        return (new AssistantActionDomainObject)->setId($id)->setSummary($summary);
    }

    public function test_an_event_of_another_organizer_is_refused_and_written_to_the_journal(): void
    {
        $this->journal->shouldReceive('refuse')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $reason) => $context->command === 'anicka:vente-tarif'
                && $context->organizerId === null && (int) $context->params['organisateur'] === 9 && str_contains($reason, '7'))
            ->andReturn($this->action(31));
        $this->journal->shouldNotReceive('start');

        [$exitCode, $json] = $this->callCommand('anicka:vente-tarif', [
            '--organisateur' => 9, '--evenement' => 7, '--tarif' => 12, '--etat' => 'fermee',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($json['ok']);
        $this->assertSame('refuse', $json['statut']);
        $this->assertSame(31, $json['journal_id']);
    }

    public function test_a_missing_option_is_refused_before_anything_runs(): void
    {
        $this->journal->shouldReceive('refuse')->once()->andReturn($this->action(32));

        [$exitCode, $json] = $this->callCommand('anicka:vente-tarif', ['--organisateur' => 4, '--evenement' => 7, '--etat' => 'fermee']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--tarif', $json['erreur']);
    }

    public function test_level_one_runs_at_once_and_records_the_result(): void
    {
        $products = Mockery::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('findFirstWhere')->andReturn((new ProductDomainObject)->setId(12)->setTitle('Prévente'));
        $this->app->instance(ProductRepositoryInterface::class, $products);

        $handler = Mockery::mock(SetProductSalesPausedHandler::class);
        $handler->shouldReceive('handle')->once()
            ->withArgs(fn (SetProductSalesPausedDTO $dto) => $dto->eventId === 7 && $dto->productId === 12 && $dto->salesPaused)
            ->andReturn((new ProductDomainObject)->setId(12)->setTitle('Prévente')->setIsSalesPaused(true));
        $this->app->instance(SetProductSalesPausedHandler::class, $handler);

        $this->journal->shouldReceive('start')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $summary) => $context->level === 1
                && $context->requestedBy === 'Jo, SMS du 11/11'
                && $context->params === ['tarif' => '12', 'etat' => 'fermee', 'organisateur' => 4, 'evenement' => 7]
                && str_contains($summary, 'Prévente'))
            ->andReturn($this->action(40));
        $this->journal->shouldReceive('markExecuted')->once()->with(40, ['tarif_id' => 12, 'tarif' => 'Prévente', 'vente' => 'fermee']);

        [$exitCode, $json] = $this->callCommand('anicka:vente-tarif', [
            '--organisateur' => 4, '--evenement' => 7, '--tarif' => 12, '--etat' => 'fermee', '--par' => 'Jo, SMS du 11/11',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('execute', $json['statut']);
        $this->assertSame('fermee', $json['resultat']['vente']);
    }

    private function productWithOnePrice(): void
    {
        $product = (new ProductDomainObject)->setId(12)->setTitle('Prévente')->setType('PAID')
            ->setProductPrices(collect([(new ProductPriceDomainObject)->setId(91)->setProductId(12)->setPrice(15.0)]));

        $products = Mockery::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('loadRelation')->andReturnSelf();
        $products->shouldReceive('findFirstWhere')->with(['id' => 12, 'event_id' => 7])->andReturn($product);
        $this->app->instance(ProductRepositoryInterface::class, $products);
    }

    private function priceHandler(bool $called): MockInterface
    {
        $handler = Mockery::mock(ChangeProductPriceHandler::class);
        $this->app->instance(ChangeProductPriceHandler::class, $handler);

        if (! $called) {
            $handler->shouldNotReceive('handle');
        }

        return $handler;
    }

    public function test_level_two_without_token_only_returns_a_summary_and_a_token(): void
    {
        $this->productWithOnePrice();
        $this->priceHandler(called: false);

        $this->journal->shouldReceive('requestConfirmation')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $summary) => $context->level === 2
                && str_contains($summary, '15,00 EUR') && str_contains($summary, '20,00 EUR'))
            ->andReturn(new AssistantConfirmationRequestDTO(actionId: 50, token: 'ABCD2345', expiresAt: '2026-11-11T18:00:00+00:00'));

        [$exitCode, $json] = $this->callCommand('anicka:changer-prix', ['--organisateur' => 4, '--evenement' => 7, '--tarif' => 12, '--prix' => '20']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('confirmation_requise', $json['statut']);
        $this->assertSame('ABCD2345', $json['jeton']);
        $this->assertSame(50, $json['journal_id']);
    }

    public function test_level_two_with_its_token_runs_the_handler(): void
    {
        $this->productWithOnePrice();
        $this->priceHandler(called: true)->shouldReceive('handle')->once()
            ->withArgs(fn (ChangeProductPriceDTO $dto) => $dto->eventId === 7 && $dto->productId === 12
                && $dto->productPriceId === 91 && $dto->price === 20.0)
            ->andReturn((new ProductPriceDomainObject)->setId(91)->setPrice(20.0));

        $this->journal->shouldReceive('consumeConfirmation')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $token) => $token === 'ABCD2345'
                && $context->params === ['tarif' => '12', 'prix' => '20', 'organisateur' => 4, 'evenement' => 7])
            ->andReturn($this->action(50, 'Passer le prix de « Prévente » de 15,00 EUR à 20,00 EUR'));
        $this->journal->shouldReceive('markExecuted')->once()->with(50, [
            'tarif_id' => 12, 'niveau_prix_id' => 91, 'ancien_prix' => 15.0, 'nouveau_prix' => 20.0, 'devise' => 'EUR',
        ]);

        [$exitCode, $json] = $this->callCommand('anicka:changer-prix', [
            '--organisateur' => 4, '--evenement' => 7, '--tarif' => 12, '--prix' => '20', '--confirme' => 'ABCD2345',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('execute', $json['statut']);
        $this->assertSame('Passer le prix de « Prévente » de 15,00 EUR à 20,00 EUR', $json['resume']);
    }

    public function test_a_refused_token_runs_nothing(): void
    {
        $this->productWithOnePrice();
        $this->priceHandler(called: false);

        $this->journal->shouldReceive('consumeConfirmation')->once()
            ->andThrow(new AssistantConfirmationException('Ce jeton de confirmation a déjà servi.'));
        $this->journal->shouldReceive('refuse')->once()->andReturn($this->action(51));

        [$exitCode, $json] = $this->callCommand('anicka:changer-prix', [
            '--organisateur' => 4, '--evenement' => 7, '--tarif' => 12, '--prix' => '20', '--confirme' => 'ABCD2345',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('Ce jeton de confirmation a déjà servi.', $json['erreur']);
    }

    public function test_an_sms_campaign_at_night_is_refused(): void
    {
        $handler = Mockery::mock(ScheduleSmsReminderHandler::class);
        $handler->shouldNotReceive('handle');
        $this->app->instance(ScheduleSmsReminderHandler::class, $handler);

        $this->journal->shouldReceive('refuse')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $reason) => str_contains($reason, '21'))
            ->andReturn($this->action(60));

        [$exitCode] = $this->callCommand('anicka:campagne-sms', [
            '--organisateur' => 4, '--evenement' => 7, '--message' => 'Les portes ouvrent', '--envoi' => '2026-12-01 22:30',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_a_refund_above_the_ticket_price_is_refused_before_asking_for_a_token(): void
    {
        $order = (new OrderDomainObject)->setId(1520)->setEventId(7)->setCurrency('EUR')->setTotalGross(21.5)
            ->setShortId('o_abc')->setFirstName('Awa')->setLastName('Said');

        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $orders->shouldReceive('findFirstWhere')->with(['id' => 1520, 'event_id' => 7])->andReturn($order);
        $this->app->instance(OrderRepositoryInterface::class, $orders);

        $share = Mockery::mock(OrderOrganizerShareService::class);
        $share->shouldReceive('getShare')->andReturn(new OrderOrganizerShareDTO(
            totalGross: 21.5, serviceFees: 1.5, refundProtection: 0.0, organizerSales: 20.0, refundableToBuyer: 20.0,
        ));
        $this->app->instance(OrderOrganizerShareService::class, $share);

        $handler = Mockery::mock(RefundOrderHandler::class);
        $handler->shouldNotReceive('handle');
        $this->app->instance(RefundOrderHandler::class, $handler);

        $this->journal->shouldNotReceive('requestConfirmation');
        $this->journal->shouldReceive('refuse')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $reason) => str_contains($reason, '20,00 EUR'))
            ->andReturn($this->action(70));

        [$exitCode, $json] = $this->callCommand('anicka:rembourser', [
            '--organisateur' => 4, '--evenement' => 7, '--commande' => 1520, '--montant' => '21,50',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('refuse', $json['statut']);
    }

    public function test_an_email_campaign_to_ticket_holders_needs_the_products(): void
    {
        $this->journal->shouldReceive('refuse')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $reason) => str_contains($reason, '--tarifs'))
            ->andReturn($this->action(80));

        [$exitCode] = $this->callCommand('anicka:campagne-email', [
            '--organisateur' => 4, '--evenement' => 7, '--sujet' => 'Infos', '--message' => 'Bonjour', '--cible' => 'tarifs',
        ]);

        $this->assertSame(1, $exitCode);
    }
}
