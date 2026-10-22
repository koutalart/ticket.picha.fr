<?php

declare(strict_types=1);

namespace Tests\Support;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use Mockery;

/**
 * Replaces the database lookups of PublicBaseUrlResolver: organizers (id => custom domain or null) and events (id => organizer id).
 */
trait FakesPublicBaseUrl
{
    /**
     * @param  array<int, string|null>  $customDomainByOrganizerId
     * @param  array<int, int>  $organizerIdByEventId
     */
    protected function fakePublicBaseUrl(array $customDomainByOrganizerId = [], array $organizerIdByEventId = []): PublicBaseUrlResolver
    {
        $organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $organizerRepository->shouldReceive('findFirst')->andReturnUsing(
            fn (int $id) => (new OrganizerDomainObject)
                ->setId($id)
                ->setStatus('LIVE')
                ->setCustomDomain($customDomainByOrganizerId[$id] ?? null)
        );

        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('findFirst')->andReturnUsing(
            fn (int $id) => (new EventDomainObject)->setId($id)->setOrganizerId($organizerIdByEventId[$id] ?? $id)
        );

        $resolver = new PublicBaseUrlResolver($organizerRepository, $eventRepository);
        $this->app->instance(PublicBaseUrlResolver::class, $resolver);

        return $resolver;
    }
}
