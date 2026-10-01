<?php

namespace Tests\Unit\Services\Application\Handlers\Admin;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidCustomDomainException;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerCustomDomainDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerCustomDomainHandler;
use Mockery;
use Tests\TestCase;

class UpdateOrganizerCustomDomainHandlerTest extends TestCase
{
    private OrganizerRepositoryInterface $repository;
    private UpdateOrganizerCustomDomainHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://ticket.picha.fr']);
        $this->repository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->handler = new UpdateOrganizerCustomDomainHandler($this->repository);
    }

    public function testAssignsNormalizedDomain(): void
    {
        $this->expectOrganizerLookup();
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['custom_domain' => 'innocent976.yt'])
            ->once()
            ->andReturn(null);

        $updated = new OrganizerDomainObject();
        $this->repository->shouldReceive('updateFromArray')
            ->with(6, ['custom_domain' => 'innocent976.yt'])
            ->once()
            ->andReturn($updated);

        $result = $this->handler->handle($this->dto('  https://WWW.Innocent976.yt/events?x=1 '));

        $this->assertSame($updated, $result);
    }

    public function testClearsDomainWhenEmpty(): void
    {
        $this->expectOrganizerLookup();
        $this->repository->shouldReceive('updateFromArray')
            ->with(6, ['custom_domain' => null])
            ->once()
            ->andReturn(new OrganizerDomainObject());

        $this->handler->handle($this->dto(''));
    }

    public function testRejectsDomainOfAnotherOrganizer(): void
    {
        $this->expectOrganizerLookup();
        $other = (new OrganizerDomainObject())->setId(9);
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['custom_domain' => 'innocent976.yt'])
            ->andReturn($other);
        $this->repository->shouldNotReceive('updateFromArray');

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->dto('innocent976.yt'));
    }

    public function testRejectsPlatformDomain(): void
    {
        $this->expectOrganizerLookup();
        $this->repository->shouldNotReceive('updateFromArray');

        $this->expectException(InvalidCustomDomainException::class);

        $this->handler->handle($this->dto('staging.ticket.picha.fr'));
    }

    public function testRejectsInvalidDomain(): void
    {
        $this->expectOrganizerLookup();
        $this->repository->shouldNotReceive('updateFromArray');

        $this->expectException(InvalidCustomDomainException::class);

        $this->handler->handle($this->dto('not a domain'));
    }

    public function testRejectsOrganizerFromAnotherAccount(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['id' => 6, 'account_id' => 1])
            ->andReturn(null);

        $this->expectException(OrganizerNotFoundException::class);

        $this->handler->handle($this->dto('innocent976.yt'));
    }

    private function expectOrganizerLookup(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['id' => 6, 'account_id' => 1])
            ->once()
            ->andReturn((new OrganizerDomainObject())->setId(6));
    }

    private function dto(?string $domain): UpdateOrganizerCustomDomainDTO
    {
        return new UpdateOrganizerCustomDomainDTO(accountId: 1, organizerId: 6, customDomain: $domain);
    }
}
