<?php

namespace Tests\Unit\Console;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class OrganizerSectionsCommandTest extends TestCase
{
    private MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->app->instance(OrganizerRepositoryInterface::class, $this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function organizer(array|string|null $sections = null): OrganizerDomainObject
    {
        $organizer = (new OrganizerDomainObject)
            ->setId(17)
            ->setName('El Djine Officiel')
            ->setEnabledSections($sections);

        $this->repository->shouldReceive('findFirst')->with(17)->andReturn($organizer);

        return $organizer;
    }

    public function test_only_saves_valid_sections_in_canonical_order(): void
    {
        $this->organizer();

        $this->repository->shouldReceive('updateFromArray')
            ->once()
            ->with(17, ['enabled_sections' => ['reports', 'qr-codes', 'publications']])
            ->andReturn(new OrganizerDomainObject);

        $this->artisan('organizer:sections', ['organizer' => 17, '--only' => 'publications, QR-codes,reports,publications'])
            ->expectsOutput('"El Djine Officiel" (#17): all sections -> reports, qr-codes, publications + settings')
            ->assertSuccessful();
    }

    public function test_unknown_section_is_rejected_without_saving(): void
    {
        $this->organizer();
        $this->repository->shouldNotReceive('updateFromArray');

        $this->artisan('organizer:sections', ['organizer' => 17, '--only' => 'publications,settings,billing'])
            ->expectsOutputToContain('Unknown section(s): settings, billing')
            ->assertFailed();
    }

    public function test_empty_only_is_rejected(): void
    {
        $this->organizer();
        $this->repository->shouldNotReceive('updateFromArray');

        $this->artisan('organizer:sections', ['organizer' => 17, '--only' => ' , '])
            ->expectsOutput('At least one section is required.')
            ->assertFailed();
    }

    public function test_all_resets_to_null(): void
    {
        $this->organizer('["publications","qr-codes","reports"]');

        $this->repository->shouldReceive('updateFromArray')
            ->once()
            ->with(17, ['enabled_sections' => null])
            ->andReturn(new OrganizerDomainObject);

        $this->artisan('organizer:sections', ['organizer' => 17, '--all' => true])
            ->expectsOutput('"El Djine Officiel" (#17): publications, qr-codes, reports + settings -> all sections')
            ->assertSuccessful();
    }

    public function test_dry_run_does_not_save(): void
    {
        $this->organizer();
        $this->repository->shouldNotReceive('updateFromArray');

        $this->artisan('organizer:sections', ['organizer' => 17, '--only' => 'publications', '--dry-run' => true])
            ->expectsOutput('[dry-run] "El Djine Officiel" (#17): all sections -> publications + settings')
            ->assertSuccessful();
    }

    public function test_only_and_all_together_are_rejected(): void
    {
        $this->repository->shouldNotReceive('findFirst');
        $this->repository->shouldNotReceive('updateFromArray');

        $this->artisan('organizer:sections', ['organizer' => 17, '--only' => 'sms', '--all' => true])
            ->assertFailed();
    }

    public function test_without_option_shows_current_sections(): void
    {
        $this->organizer(['publications']);
        $this->repository->shouldNotReceive('updateFromArray');

        $this->artisan('organizer:sections', ['organizer' => 17])
            ->expectsOutput('"El Djine Officiel" (#17): publications + settings')
            ->assertSuccessful();
    }

    public function test_unknown_organizer_fails(): void
    {
        $this->repository->shouldReceive('findFirst')->with(999)->andReturnNull();
        $this->repository->shouldNotReceive('updateFromArray');

        $this->artisan('organizer:sections', ['organizer' => 999, '--all' => true])
            ->expectsOutput('Organizer 999 not found')
            ->assertFailed();
    }
}
