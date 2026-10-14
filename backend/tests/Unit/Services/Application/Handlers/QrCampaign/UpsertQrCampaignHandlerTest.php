<?php

namespace Tests\Unit\Services\Application\Handlers\QrCampaign;

use HiEvents\DomainObjects\QrCampaignDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrCampaign\UpsertQrCampaignHandler;
use Mockery;
use Tests\TestCase;

class UpsertQrCampaignHandlerTest extends TestCase
{
    public function test_creates_a_campaign_with_a_clean_name(): void
    {
        $repository = Mockery::mock(QrCampaignRepositoryInterface::class);
        $repository->shouldReceive('nameExists')->with(4, 'JPO 2026', null)->andReturnFalse();
        $repository->shouldReceive('create')->once()
            ->with(['account_id' => 1, 'organizer_id' => 4, 'name' => 'JPO 2026'])
            ->andReturn((new QrCampaignDomainObject)->setId(3)->setName('JPO 2026'));

        $campaign = (new UpsertQrCampaignHandler($repository))->create(1, 4, '  <b>JPO 2026</b> ');

        $this->assertSame(3, $campaign->getId());
    }

    public function test_refuses_a_duplicate_name(): void
    {
        $repository = Mockery::mock(QrCampaignRepositoryInterface::class);
        $repository->shouldReceive('nameExists')->with(4, 'Affiches', 7)->andReturnTrue();
        $repository->shouldNotReceive('updateByIdWhere');

        $this->expectException(ResourceConflictException::class);

        (new UpsertQrCampaignHandler($repository))->rename(4, 7, 'Affiches');
    }
}
