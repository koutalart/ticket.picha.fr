<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\SmsReplyTemplateDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReplyTemplateValidationException;
use HiEvents\Repository\Interfaces\SmsReplyTemplateRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsReplyTemplateDTO;
use HiEvents\Services\Domain\Sms\SmsReplyTemplateService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsReplyTemplateServiceTest extends TestCase
{
    private SmsReplyTemplateRepositoryInterface|MockInterface $repository;

    private SmsReplyTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(SmsReplyTemplateRepositoryInterface::class);
        $this->service = new SmsReplyTemplateService($this->repository);
    }

    public function test_create_stores_sanitized_template(): void
    {
        $this->repository->shouldReceive('countWhere')->with(['organizer_id' => 4])->andReturn(3);
        $this->repository->shouldReceive('create')
            ->once()
            ->with([
                'organizer_id' => 4,
                'name' => 'Horaires',
                'content' => 'Bonjour {prenom}, les portes ouvrent à 19h.',
            ])
            ->andReturn((new SmsReplyTemplateDomainObject)->setId(1));

        $this->service->create(new SmsReplyTemplateDTO(
            organizerId: 4,
            name: "  <i>Horaires</i>\n ",
            content: '<p>Bonjour {prenom}, les portes ouvrent à 19h.</p>',
        ));
    }

    public function test_create_rejects_template_empty_after_sanitizing(): void
    {
        $this->repository->shouldReceive('countWhere')->andReturn(0);
        $this->repository->shouldNotReceive('create');

        $this->expectException(SmsReplyTemplateValidationException::class);

        $this->service->create(new SmsReplyTemplateDTO(organizerId: 4, name: 'Vide', content: '<br><br>'));
    }

    public function test_create_is_limited_per_organizer(): void
    {
        $this->repository->shouldReceive('countWhere')->andReturn(SmsReplyTemplateService::MAX_TEMPLATES_PER_ORGANIZER);
        $this->repository->shouldNotReceive('create');

        $this->expectException(SmsReplyTemplateValidationException::class);

        $this->service->create(new SmsReplyTemplateDTO(organizerId: 4, name: 'Trop', content: 'Texte'));
    }

    public function test_update_is_scoped_to_the_organizer(): void
    {
        $this->repository->shouldReceive('findFirstWhere')->with(['id' => 9, 'organizer_id' => 4])->andReturnNull();
        $this->repository->shouldNotReceive('updateByIdWhere');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->update(9, new SmsReplyTemplateDTO(organizerId: 4, name: 'Nom', content: 'Texte'));
    }

    public function test_update_saves_new_values(): void
    {
        $where = ['id' => 9, 'organizer_id' => 4];
        $this->repository->shouldReceive('findFirstWhere')->with($where)->andReturn(new SmsReplyTemplateDomainObject);
        $this->repository->shouldReceive('updateByIdWhere')
            ->once()
            ->with(9, ['name' => 'Accès', 'content' => 'Parking gratuit.'], $where)
            ->andReturn(new SmsReplyTemplateDomainObject);

        $this->service->update(9, new SmsReplyTemplateDTO(organizerId: 4, name: 'Accès', content: 'Parking gratuit.'));
    }

    public function test_delete_removes_only_the_organizer_template(): void
    {
        $where = ['id' => 9, 'organizer_id' => 4];
        $this->repository->shouldReceive('findFirstWhere')->with($where)->andReturn(new SmsReplyTemplateDomainObject);
        $this->repository->shouldReceive('deleteWhere')->once()->with($where);

        $this->service->delete(4, 9);
    }
}
