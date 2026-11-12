<?php

namespace Tests\Unit\Console\Assistant;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\AssistantActionDomainObject;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CreateDraftEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\CreatedDraftEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\CreateDraftEventDTO;
use HiEvents\Services\Domain\Assistant\AssistantActionJournal;
use HiEvents\Services\Domain\Assistant\DTO\AssistantActionContextDTO;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateEventAssistantCommandTest extends TestCase
{
    private MockInterface $journal;

    private MockInterface $events;

    private MockInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://ticket.picha.fr/']);

        $this->journal = Mockery::mock(AssistantActionJournal::class);
        $this->events = Mockery::mock(EventRepositoryInterface::class);
        $this->handler = Mockery::mock(CreateDraftEventHandler::class);
        $organizers = Mockery::mock(OrganizerRepositoryInterface::class);
        $accountUsers = Mockery::mock(AccountUserRepositoryInterface::class);

        $this->app->instance(AssistantActionJournal::class, $this->journal);
        $this->app->instance(EventRepositoryInterface::class, $this->events);
        $this->app->instance(OrganizerRepositoryInterface::class, $organizers);
        $this->app->instance(AccountUserRepositoryInterface::class, $accountUsers);
        $this->app->instance(CreateDraftEventHandler::class, $this->handler);

        $organizers->shouldReceive('findFirstWhere')->with(['id' => 4])->andReturn(
            (new OrganizerDomainObject)->setId(4)->setAccountId(3)->setName('Innocent Event')
        );
        $accountUsers->shouldReceive('findFirstWhere')->with(['account_id' => 3, 'is_account_owner' => true])
            ->andReturn((new AccountUserDomainObject)->setUserId(11));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function callCommand(array $options): array
    {
        $exitCode = Artisan::call('anicka:creer-evenement', $options);
        $lines = array_values(array_filter(
            explode("\n", trim(Artisan::output())),
            fn (string $line) => str_starts_with($line, '{'),
        ));

        return [$exitCode, json_decode(end($lines), true)];
    }

    private function commandOptions(array $overrides = []): array
    {
        return [
            '--organisateur' => 4,
            '--titre' => 'Soirée Gala',
            '--description' => '<p>Grande soirée</p>',
            '--debut' => '2026-12-24 20:00',
            '--fin' => '2026-12-25 04:00',
            '--lieu' => 'Pôle culturel, Chirongui',
            '--tarifs' => '[{"nom":"Prévente","prix":"15,50","quantite":200},{"nom":"Invitation","prix":0}]',
            '--questions' => '[{"libelle":"Ville","type":"texte","obligatoire":true},'
                .'{"libelle":"Âge","type":"nombre"},{"libelle":"Téléphone","type":"téléphone","obligatoire":"oui"},'
                .'{"libelle":"Repas","type":"choix","options":["Viande","Poisson","Viande"]}]',
            ...$overrides,
        ];
    }

    public function test_it_creates_a_draft_event_in_mayotte_time_and_returns_the_manage_link(): void
    {
        $this->events->shouldReceive('findFirstWhere')->once()
            ->with(['organizer_id' => 4, 'title' => 'Soirée Gala', 'start_date' => '2026-12-24 17:00:00'])
            ->andReturnNull();

        $this->journal->shouldReceive('start')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $summary) => $context->level === 1
                && $context->command === 'anicka:creer-evenement'
                && $context->organizerId === 4
                && str_contains($summary, 'Soirée Gala')
                && str_contains($summary, 'Prévente 15,50 EUR x200')
                && str_contains($summary, 'Invitation gratuit')
                && str_contains($summary, '4 question'))
            ->andReturn((new AssistantActionDomainObject)->setId(70));

        $this->handler->shouldReceive('handle')->once()
            ->withArgs(function (CreateDraftEventDTO $dto) {
                [$prevente, $invitation] = $dto->tickets;
                [$ville, $age, $phone, $repas] = $dto->questions;

                return $dto->organizer_id === 4 && $dto->account_id === 3 && $dto->user_id === 11
                    && $dto->timezone === 'Indian/Mayotte'
                    && $dto->start_date === '2026-12-24 20:00:00'
                    && $dto->end_date === '2026-12-25 04:00:00'
                    && $dto->venue_name === 'Pôle culturel, Chirongui'
                    && $prevente->title === 'Prévente' && $prevente->price === 15.5 && $prevente->quantity === 200
                    && $invitation->isFree() && $invitation->quantity === null
                    && $ville->type === QuestionTypeEnum::SINGLE_LINE_TEXT && $ville->required
                    && $age->type === QuestionTypeEnum::SINGLE_LINE_TEXT && ! $age->required && $age->description !== null
                    && $phone->type === QuestionTypeEnum::PHONE && $phone->required
                    && $repas->type === QuestionTypeEnum::RADIO && $repas->options === ['Viande', 'Poisson'];
            })
            ->andReturn(new CreatedDraftEventDTO(
                event: (new EventDomainObject)->setId(25)->setStatus(EventStatus::DRAFT->name),
                ticket_ids: [80, 81],
                question_ids: [90, 91, 92, 93],
            ));
        $this->journal->shouldReceive('markExecuted')->once()->with(70, Mockery::on(fn (array $result) => $result['evenement_id'] === 25));

        [$exitCode, $json] = $this->callCommand($this->commandOptions());

        $this->assertSame(0, $exitCode);
        $this->assertSame('execute', $json['statut']);
        $this->assertSame('DRAFT', $json['resultat']['statut']);
        $this->assertSame([80, 81], $json['resultat']['tarifs']);
        $this->assertSame('https://ticket.picha.fr/manage/event/25', $json['resultat']['gerer']);
    }

    public function test_an_event_that_already_exists_is_refused(): void
    {
        $this->events->shouldReceive('findFirstWhere')->once()->andReturn((new EventDomainObject)->setId(25));
        $this->journal->shouldReceive('refuse')->once()
            ->withArgs(fn (AssistantActionContextDTO $context, string $reason) => str_contains($reason, '25'))
            ->andReturn((new AssistantActionDomainObject)->setId(71));
        $this->handler->shouldNotReceive('handle');

        [$exitCode, $json] = $this->callCommand($this->commandOptions());

        $this->assertSame(1, $exitCode);
        $this->assertSame('refuse', $json['statut']);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_are_refused_before_anything_runs(array $overrides, string $expected): void
    {
        $this->events->shouldNotReceive('findFirstWhere');
        $this->journal->shouldNotReceive('start');
        $this->handler->shouldNotReceive('handle');
        $this->journal->shouldReceive('refuse')->once()->andReturn((new AssistantActionDomainObject)->setId(72));

        [$exitCode, $json] = $this->callCommand($this->commandOptions($overrides));

        $this->assertSame(1, $exitCode);
        $this->assertSame('refuse', $json['statut']);
        $this->assertStringContainsString($expected, $json['erreur']);
    }

    public static function invalidOptions(): array
    {
        return [
            'no title' => [['--titre' => ''], '--titre'],
            'bad start' => [['--debut' => '24/12/2026 20h'], '--debut'],
            'end before start' => [['--fin' => '2026-12-24 19:00'], 'fin'],
            'no tickets' => [['--tarifs' => '[]'], '--tarifs'],
            'tickets not json' => [['--tarifs' => 'Prévente 15'], '--tarifs'],
            'negative price' => [['--tarifs' => '[{"nom":"A","prix":-1}]'], 'prix'],
            'missing price' => [['--tarifs' => '[{"nom":"A"}]'], 'prix'],
            'bad quantity' => [['--tarifs' => '[{"nom":"A","prix":5,"quantite":0}]'], 'quantite'],
            'unknown question type' => [['--questions' => '[{"libelle":"Photo","type":"fichier"}]'], 'type'],
            'choice without options' => [['--questions' => '[{"libelle":"Repas","type":"choix","options":["Viande"]}]'], 'choix'],
        ];
    }
}
