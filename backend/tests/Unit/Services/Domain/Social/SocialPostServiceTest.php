<?php

namespace Tests\Unit\Services\Domain\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Domain\Social\SocialPostService;
use HiEvents\Services\Domain\Social\SocialPostValidator;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SocialPostServiceTest extends TestCase
{
    private SocialPostRepositoryInterface|MockInterface $postRepository;

    private MetaConnectionService|MockInterface $connectionService;

    private SocialPostService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postRepository = Mockery::mock(SocialPostRepositoryInterface::class);
        $this->connectionService = Mockery::mock(MetaConnectionService::class);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->service = new SocialPostService($this->postRepository, $this->connectionService, new SocialPostValidator, $database);
    }

    public function test_post_prepared_by_the_assistant_waits_for_approval(): void
    {
        $this->givenConnection();
        $created = [];
        $this->postRepository->shouldReceive('create')->twice()->andReturnUsing(function (array $attributes) use (&$created) {
            $created[] = $attributes;

            return new SocialPostDomainObject;
        });

        $posts = $this->service->create($this->dto(SocialPostSource::ASSISTANT, [SocialNetwork::FACEBOOK, SocialNetwork::INSTAGRAM]));

        $this->assertCount(2, $posts);
        foreach ($created as $attributes) {
            $this->assertSame(SocialPostStatus::PENDING_APPROVAL->name, $attributes[SocialPostDomainObjectAbstract::STATUS]);
            $this->assertSame(SocialPostSource::ASSISTANT->name, $attributes[SocialPostDomainObjectAbstract::SOURCE]);
            $this->assertNull($attributes[SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT]);
            $this->assertNull($attributes[SocialPostDomainObjectAbstract::APPROVED_BY_USER_ID]);
        }
        $this->assertSame(['FACEBOOK', 'INSTAGRAM'], array_column($created, SocialPostDomainObjectAbstract::NETWORK));
    }

    public function test_post_created_by_a_person_is_scheduled_and_approved_by_them(): void
    {
        $this->givenConnection();
        $this->postRepository->shouldReceive('create')->once()
            ->withArgs(fn (array $attributes) => $attributes[SocialPostDomainObjectAbstract::STATUS] === SocialPostStatus::SCHEDULED->name
                && $attributes[SocialPostDomainObjectAbstract::APPROVED_BY_USER_ID] === 7
                && $attributes[SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT] !== null
                && $attributes[SocialPostDomainObjectAbstract::CAPTION] === 'Halloween ce soir')
            ->andReturn(new SocialPostDomainObject);

        $this->service->create($this->dto(SocialPostSource::PICHA, [SocialNetwork::INSTAGRAM], userId: 7));
    }

    public function test_post_is_refused_without_a_connected_page(): void
    {
        $this->connectionService->shouldReceive('findForOrganizer')->andReturnNull();
        $this->postRepository->shouldNotReceive('create');

        $this->expectException(InvalidSocialPostException::class);

        $this->service->create($this->dto(SocialPostSource::PICHA, [SocialNetwork::FACEBOOK]));
    }

    public function test_instagram_is_refused_without_a_linked_professional_account(): void
    {
        $this->givenConnection(instagramId: null);

        $this->expectException(InvalidSocialPostException::class);

        $this->service->create($this->dto(SocialPostSource::PICHA, [SocialNetwork::INSTAGRAM]));
    }

    public function test_approval_schedules_the_post_and_records_who_approved(): void
    {
        $this->postRepository->shouldReceive('findFirstWhere')->andReturn($this->postIn(SocialPostStatus::PENDING_APPROVAL));
        $this->postRepository->shouldReceive('updateFromArray')->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 9
                && $attributes[SocialPostDomainObjectAbstract::STATUS] === SocialPostStatus::SCHEDULED->name
                && $attributes[SocialPostDomainObjectAbstract::APPROVED_BY_USER_ID] === 7
                && $attributes[SocialPostDomainObjectAbstract::ATTEMPTS] === 0
                && $attributes[SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT] instanceof Carbon)
            ->andReturn(new SocialPostDomainObject);

        $this->service->schedule(4, 9, 7);
    }

    public function test_published_post_can_no_longer_be_changed(): void
    {
        $this->postRepository->shouldReceive('findFirstWhere')->andReturn($this->postIn(SocialPostStatus::PUBLISHED));
        $this->postRepository->shouldNotReceive('updateFromArray');

        $this->expectException(SocialPostNotEditableException::class);

        $this->service->cancel(4, 9);
    }

    public function test_cancel_removes_the_post_from_the_queue(): void
    {
        $this->postRepository->shouldReceive('findFirstWhere')->andReturn($this->postIn(SocialPostStatus::SCHEDULED));
        $this->postRepository->shouldReceive('updateFromArray')->once()
            ->with(9, [
                SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::CANCELLED->name,
                SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => null,
            ])
            ->andReturn(new SocialPostDomainObject);

        $this->service->cancel(4, 9);
    }

    private function dto(SocialPostSource $source, array $networks, ?int $userId = null): CreateSocialPostsDTO
    {
        return new CreateSocialPostsDTO(
            accountId: 3,
            organizerId: 4,
            userId: $userId,
            networks: $networks,
            format: SocialPostFormat::IMAGE,
            caption: "  <b>Halloween</b> ce soir \r\n",
            media: [new SocialMediaItemDTO('https://cdn.test/affiche.jpg', SocialMediaType::IMAGE)],
            link: null,
            scheduledAt: null,
            source: $source,
        );
    }

    private function givenConnection(?string $instagramId = '1784'): void
    {
        $this->connectionService->shouldReceive('findForOrganizer')->with(3, 4)->andReturn(
            (new OrganizerSocialConnectionDomainObject)
                ->setId(5)
                ->setStatus(SocialConnectionStatus::ACTIVE->name)
                ->setInstagramAccountId($instagramId),
        );
    }

    private function postIn(SocialPostStatus $status): SocialPostDomainObject
    {
        return (new SocialPostDomainObject)->setId(9)->setOrganizerId(4)->setStatus($status->name);
    }
}
