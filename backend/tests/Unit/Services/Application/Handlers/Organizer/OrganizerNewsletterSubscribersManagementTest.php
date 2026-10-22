<?php

namespace Tests\Unit\Services\Application\Handlers\Organizer;

use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exports\OrganizerNewsletterSubscribersExport;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Resources\Organizer\OrganizerNewsletterSubscriberResource;
use HiEvents\Services\Application\Handlers\Organizer\DeleteOrganizerNewsletterSubscriberHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\NewsletterSubscriberSearchDTO;
use HiEvents\Services\Application\Handlers\Organizer\GetOrganizerNewsletterSubscribersHandler;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Tests\TestCase;

class OrganizerNewsletterSubscribersManagementTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private OrganizerNewsletterSubscriberRepositoryInterface|MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(OrganizerNewsletterSubscriberRepositoryInterface::class);
    }

    public function test_list_is_scoped_to_the_organizer_with_search_and_pagination(): void
    {
        $paginator = new LengthAwarePaginator([$this->subscriber()], 1, 25, 2);
        $search = new NewsletterSubscriberSearchDTO(query: 'jo@', page: 2, perPage: 25);

        $this->repository->shouldReceive('searchForOrganizer')
            ->once()
            ->with(8, $search)
            ->andReturn($paginator);

        $result = (new GetOrganizerNewsletterSubscribersHandler($this->repository))->handle(8, $search);

        $this->assertSame($paginator, $result);
    }

    public function test_export_fetches_every_matching_subscriber_of_the_organizer(): void
    {
        $this->repository->shouldReceive('searchForOrganizer')
            ->once()
            ->withArgs(fn(int $organizerId, NewsletterSubscriberSearchDTO $search) => $organizerId === 8
                && $search->query === 'concert'
                && $search->page === 1
                && $search->perPage === GetOrganizerNewsletterSubscribersHandler::EXPORT_LIMIT)
            ->andReturn(new LengthAwarePaginator([], 0, 1));

        (new GetOrganizerNewsletterSubscribersHandler($this->repository))->handleExport(8, 'concert');
    }

    public function test_delete_only_targets_a_subscriber_of_the_organizer(): void
    {
        $this->repository->shouldReceive('deleteWhere')
            ->once()
            ->with(['id' => 31, 'organizer_id' => 8])
            ->andReturn(1);

        (new DeleteOrganizerNewsletterSubscriberHandler($this->repository))->handle(8, 31);
    }

    public function test_delete_of_another_organizer_subscriber_is_not_found(): void
    {
        $this->repository->shouldReceive('deleteWhere')
            ->once()
            ->with(['id' => 31, 'organizer_id' => 9])
            ->andReturn(0);

        $this->expectException(ResourceNotFoundException::class);

        (new DeleteOrganizerNewsletterSubscriberHandler($this->repository))->handle(9, 31);
    }

    public function test_export_lists_email_event_consent_date_and_text(): void
    {
        $export = (new OrganizerNewsletterSubscribersExport())
            ->withData(new LengthAwarePaginator([$this->subscriber()], 1, 10));

        $this->assertCount(4, $export->headings());
        $this->assertCount(1, $export->collection());
        $this->assertSame([
            'jo@example.com',
            'Le Concert des Générations',
            '2026-10-22 09:30:00',
            "J'accepte de recevoir les actualités.",
        ], $export->map($export->collection()->first()));
    }

    public function test_resource_exposes_the_consent_proof(): void
    {
        $array = (new OrganizerNewsletterSubscriberResource($this->subscriber()))->toArray(Request::create('/'));

        $this->assertSame([
            'id' => 31,
            'email' => 'jo@example.com',
            'event_id' => 14,
            'event_title' => 'Le Concert des Générations',
            'consent_text' => "J'accepte de recevoir les actualités.",
            'consented_at' => '2026-10-22 09:30:00',
        ], $array);
    }

    private function subscriber(): OrganizerNewsletterSubscriberDomainObject
    {
        return (new OrganizerNewsletterSubscriberDomainObject())
            ->setId(31)
            ->setOrganizerId(8)
            ->setEventId(14)
            ->setEventTitle('Le Concert des Générations')
            ->setEmail('jo@example.com')
            ->setConsentText("J'accepte de recevoir les actualités.")
            ->setConsentedAt('2026-10-22 09:30:00');
    }
}
