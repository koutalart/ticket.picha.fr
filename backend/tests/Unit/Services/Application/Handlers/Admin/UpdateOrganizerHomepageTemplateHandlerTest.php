<?php

namespace Tests\Unit\Services\Application\Handlers\Admin;

use HiEvents\DomainObjects\Enums\OrganizerHomepageTemplate;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerHomepageTemplateDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerHomepageTemplateHandler;
use Mockery;
use Tests\TestCase;

class UpdateOrganizerHomepageTemplateHandlerTest extends TestCase
{
    private OrganizerRepositoryInterface $repository;
    private UpdateOrganizerHomepageTemplateHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->handler = new UpdateOrganizerHomepageTemplateHandler($this->repository);
    }

    public function testAppliesTemplate(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['id' => 6, 'account_id' => 1])
            ->andReturn((new OrganizerDomainObject())->setId(6));

        $updated = new OrganizerDomainObject();
        $this->repository->shouldReceive('updateFromArray')
            ->with(6, ['homepage_template' => 'DEFAULT'])
            ->once()
            ->andReturn($updated);

        $result = $this->handler->handle($this->dto());

        $this->assertSame($updated, $result);
    }

    public function testRejectsOrganizerFromAnotherAccount(): void
    {
        $this->repository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->repository->shouldNotReceive('updateFromArray');

        $this->expectException(OrganizerNotFoundException::class);

        $this->handler->handle($this->dto());
    }

    private function dto(): UpdateOrganizerHomepageTemplateDTO
    {
        return new UpdateOrganizerHomepageTemplateDTO(
            accountId: 1,
            organizerId: 6,
            homepageTemplate: OrganizerHomepageTemplate::DEFAULT,
        );
    }
}
