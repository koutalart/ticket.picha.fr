<?php

namespace Tests\Unit\Services\Application\Handlers\Admin;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerSiteContentDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerSiteContentHandler;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Mockery;
use Tests\TestCase;

class UpdateOrganizerSiteContentHandlerTest extends TestCase
{
    private OrganizerRepositoryInterface $repository;
    private UpdateOrganizerSiteContentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->handler = new UpdateOrganizerSiteContentHandler($this->repository, app(HtmlPurifierService::class));
    }

    public function testSanitizesHtmlFieldsBeforeSaving(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['id' => 6, 'account_id' => 1])
            ->andReturn((new OrganizerDomainObject())->setId(6));

        $this->repository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(function (int $id, array $data) {
                return $id === 6
                    && !str_contains($data['site_content']['story'], '<script')
                    && str_contains($data['site_content']['story'], '<p>Notre histoire</p>')
                    && $data['site_content']['tagline'] === 'Mayotte vibre';
            })
            ->andReturn(new OrganizerDomainObject());

        $this->handler->handle(new UpdateOrganizerSiteContentDTO(
            accountId: 1,
            organizerId: 6,
            siteContent: [
                'tagline' => 'Mayotte vibre',
                'story' => '<p>Notre histoire</p><script>alert(1)</script>',
            ],
        ));
    }

    public function testKeepsExistingKeysThatAreNotSent(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->andReturn((new OrganizerDomainObject())->setId(6)->setSiteContent([
                'redirect_to_current_event' => true,
                'videos' => [['youtube_id' => 'abcdefghijk', 'title' => 'Clip']],
                'events_intro' => 'Ancien texte',
                'tagline' => 'Ancienne accroche',
            ]));

        $this->repository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(function (int $id, array $data) {
                $content = $data['site_content'];

                return $content['redirect_to_current_event'] === true
                    && $content['videos'][0]['title'] === 'Clip'
                    && $content['events_intro'] === 'Nos prochaines dates.'
                    && $content['seo_title_home'] === 'Innocent Event | Concerts'
                    && $content['tagline'] === null;
            })
            ->andReturn(new OrganizerDomainObject());

        $this->handler->handle(new UpdateOrganizerSiteContentDTO(
            accountId: 1,
            organizerId: 6,
            siteContent: [
                'events_intro' => 'Nos prochaines dates.',
                'seo_title_home' => 'Innocent Event | Concerts',
                'tagline' => null,
            ],
        ));
    }

    public function testRejectsOrganizerFromAnotherAccount(): void
    {
        $this->repository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->repository->shouldNotReceive('updateFromArray');

        $this->expectException(OrganizerNotFoundException::class);

        $this->handler->handle(new UpdateOrganizerSiteContentDTO(accountId: 1, organizerId: 6, siteContent: []));
    }
}
