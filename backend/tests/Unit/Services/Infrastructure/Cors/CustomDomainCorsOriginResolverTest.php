<?php

namespace Tests\Unit\Services\Infrastructure\Cors;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\Cors\CustomDomainCorsOriginResolver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Mockery;
use Tests\TestCase;

class CustomDomainCorsOriginResolverTest extends TestCase
{
    private OrganizerRepositoryInterface $repository;
    private CustomDomainCorsOriginResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->resolver = new CustomDomainCorsOriginResolver($this->repository, new CacheRepository(new ArrayStore()));
    }

    public function testAllowsRegisteredCustomDomain(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['custom_domain' => 'innocent976.yt'])
            ->once()
            ->andReturn($this->organizer(OrganizerStatus::LIVE));

        $this->assertTrue($this->resolver->isAllowedOrigin('https://www.innocent976.yt'));
        $this->assertTrue($this->resolver->isAllowedOrigin('https://innocent976.yt'));
    }

    public function testRejectsUnknownDomain(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['custom_domain' => 'audit.example'])
            ->once()
            ->andReturn(null);

        $this->assertFalse($this->resolver->isAllowedOrigin('https://audit.example'));
    }

    public function testRejectsArchivedOrganizer(): void
    {
        $this->repository->shouldReceive('findFirstWhere')->once()->andReturn($this->organizer(OrganizerStatus::ARCHIVED));

        $this->assertFalse($this->resolver->isAllowedOrigin('https://innocent976.yt'));
    }

    public function testRejectsNonHttpsAndMalformedOrigins(): void
    {
        $this->repository->shouldNotReceive('findFirstWhere');

        $this->assertFalse($this->resolver->isAllowedOrigin('http://innocent976.yt'));
        $this->assertFalse($this->resolver->isAllowedOrigin('null'));
        $this->assertFalse($this->resolver->isAllowedOrigin('https://'));
    }

    public function testForgetInvalidatesCachedDecision(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->twice()
            ->andReturn(null, $this->organizer(OrganizerStatus::LIVE));

        $this->assertFalse($this->resolver->isAllowedOrigin('https://innocent976.yt'));
        $this->resolver->forget('innocent976.yt');
        $this->assertTrue($this->resolver->isAllowedOrigin('https://innocent976.yt'));
    }

    private function organizer(OrganizerStatus $status): OrganizerDomainObject
    {
        return (new OrganizerDomainObject())->setId(6)->setCustomDomain('innocent976.yt')->setStatus($status->name);
    }
}
