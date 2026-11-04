<?php

namespace Tests\Unit\Services\Domain\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use HiEvents\Services\Domain\Social\Media\SocialMediaRules;
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

    private SocialMediaFileService|MockInterface $mediaFileService;

    private SocialPostService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postRepository = Mockery::mock(SocialPostRepositoryInterface::class);
        $this->connectionService = Mockery::mock(MetaConnectionService::class);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->mediaFileService = Mockery::mock(SocialMediaFileService::class);
        $this->mediaFileService->shouldReceive('keep', 'purgeAfterDays')->byDefault();

        $this->service = new SocialPostService($this->postRepository, $this->connectionService, new SocialPostValidator(new SocialMediaRules), $database, $this->mediaFileService);
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

    public function test_imported_png_is_refused_for_instagram_but_kept_for_facebook(): void
    {
        $this->givenConnection();
        $this->mediaFileService->shouldReceive('findReady')->with(4, 42)->andReturn(
            (new SocialMediaFileDomainObject)->setId(42)->setMediaType('IMAGE')->setMimeType('image/png')->setWidth(1080)->setHeight(1080),
        );
        $this->mediaFileService->shouldReceive('keep')->once()->with([42]);
        $this->postRepository->shouldReceive('create')->once()
            ->withArgs(fn (array $attributes) => $attributes[SocialPostDomainObjectAbstract::MEDIA] === [['file_id' => 42, 'type' => 'IMAGE']])
            ->andReturn(new SocialPostDomainObject);

        $this->service->create($this->fileDto([SocialNetwork::FACEBOOK]));

        $this->expectException(InvalidSocialPostException::class);
        $this->service->create($this->fileDto([SocialNetwork::INSTAGRAM]));
    }

    public function test_imported_file_of_another_organizer_is_refused(): void
    {
        $this->givenConnection();
        $this->mediaFileService->shouldReceive('findReady')->andThrow(new SocialMediaFileNotFoundException('missing'));
        $this->postRepository->shouldNotReceive('create');

        $this->expectException(InvalidSocialPostException::class);
        $this->service->create($this->fileDto([SocialNetwork::FACEBOOK]));
    }

    public function test_post_is_refused_without_a_connected_page(): void
    {
        $this->connectionService->shouldReceive('findForOrganizer')->andReturnNull();
        $this->postRepository->shouldNotReceive('create');

        $this->expectException(InvalidSocialPostException::class);

        $this->service->create($this->dto(SocialPostSource::PICHA, [SocialNetwork::FACEBOOK]));
    }

    public function test_post_prepared_by_the_assistant_is_created_without_a_connected_page(): void
    {
        $this->connectionService->shouldNotReceive('findForOrganizer');
        $this->postRepository->shouldReceive('create')->twice()
            ->withArgs(fn (array $attributes) => $attributes[SocialPostDomainObjectAbstract::STATUS] === SocialPostStatus::PENDING_APPROVAL->name
                && $attributes[SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT] === null)
            ->andReturn(new SocialPostDomainObject);

        $posts = $this->service->create($this->dto(SocialPostSource::ASSISTANT, [SocialNetwork::FACEBOOK, SocialNetwork::INSTAGRAM]));

        $this->assertCount(2, $posts);
    }

    public function test_draft_is_created_without_a_connected_page(): void
    {
        $this->connectionService->shouldNotReceive('findForOrganizer');
        $this->postRepository->shouldReceive('create')->once()
            ->withArgs(fn (array $attributes) => $attributes[SocialPostDomainObjectAbstract::STATUS] === SocialPostStatus::DRAFT->name)
            ->andReturn(new SocialPostDomainObject);

        $this->service->create($this->dto(SocialPostSource::PICHA, [SocialNetwork::INSTAGRAM], userId: 7, asDraft: true));
    }

    public function test_format_rules_still_apply_to_a_post_to_approve_without_a_connected_page(): void
    {
        $this->connectionService->shouldNotReceive('findForOrganizer');
        $this->mediaFileService->shouldReceive('findReady')->with(4, 42)->andReturn(
            (new SocialMediaFileDomainObject)->setId(42)->setMediaType('IMAGE')->setMimeType('image/png')->setWidth(1080)->setHeight(1080),
        );
        $this->postRepository->shouldNotReceive('create');

        $this->expectException(InvalidSocialPostException::class);
        $this->expectExceptionMessage('Instagram only accepts JPEG images (.jpg).');

        $this->service->create($this->fileDto([SocialNetwork::INSTAGRAM], SocialPostSource::ASSISTANT));
    }

    public function test_instagram_account_is_only_checked_when_the_post_is_published(): void
    {
        $this->connectionService->shouldNotReceive('findForOrganizer');
        $this->postRepository->shouldReceive('create')->once()->andReturn(new SocialPostDomainObject);

        $this->service->create($this->dto(SocialPostSource::ASSISTANT, [SocialNetwork::INSTAGRAM]));
    }

    public function test_approval_without_a_connected_page_schedules_the_post(): void
    {
        $this->connectionService->shouldNotReceive('findForOrganizer');
        $this->postRepository->shouldReceive('findFirstWhere')->andReturn($this->postIn(SocialPostStatus::PENDING_APPROVAL));
        $this->postRepository->shouldReceive('updateFromArray')->once()
            ->withArgs(fn (int $id, array $attributes) => $attributes[SocialPostDomainObjectAbstract::STATUS] === SocialPostStatus::SCHEDULED->name)
            ->andReturn(new SocialPostDomainObject);

        $this->service->schedule(4, 9, 7);
    }

    public function test_new_version_replaces_each_old_post_with_the_one_of_the_same_network(): void
    {
        $this->postRepository->shouldReceive('findFirstWhere')->andReturnUsing(fn (array $where) => (new SocialPostDomainObject)
            ->setId($where[SocialPostDomainObjectAbstract::ID])
            ->setOrganizerId(4)
            ->setNetwork($where[SocialPostDomainObjectAbstract::ID] === 20 ? 'FACEBOOK' : 'INSTAGRAM')
            ->setStatus(SocialPostStatus::CHANGES_REQUESTED->name));
        $this->postRepository->shouldReceive('create')->twice()->andReturnUsing(fn (array $attributes) => (new SocialPostDomainObject)
            ->setId($attributes[SocialPostDomainObjectAbstract::NETWORK] === 'FACEBOOK' ? 30 : 31)
            ->setNetwork($attributes[SocialPostDomainObjectAbstract::NETWORK]));
        $replaced = [];
        $this->postRepository->shouldReceive('updateFromArray')->twice()->andReturnUsing(function (int $id, array $attributes) use (&$replaced) {
            $this->assertSame(SocialPostStatus::CANCELLED->name, $attributes[SocialPostDomainObjectAbstract::STATUS]);
            $replaced[$id] = $attributes[SocialPostDomainObjectAbstract::REPLACED_BY_POST_ID];

            return new SocialPostDomainObject;
        });

        $this->service->create(new CreateSocialPostsDTO(
            accountId: 3,
            organizerId: 4,
            userId: null,
            networks: [SocialNetwork::FACEBOOK, SocialNetwork::INSTAGRAM],
            format: SocialPostFormat::IMAGE,
            caption: 'Halloween',
            media: [new SocialMediaItemDTO('https://cdn.test/affiche.jpg', SocialMediaType::IMAGE)],
            link: null,
            scheduledAt: null,
            source: SocialPostSource::ASSISTANT,
            replacesPostIds: [20, 21],
        ));

        $this->assertSame([20 => 30, 21 => 31], $replaced);
    }

    public function test_changes_are_requested_with_a_clean_comment(): void
    {
        $this->postRepository->shouldReceive('findFirstWhere')->andReturn($this->postIn(SocialPostStatus::PENDING_APPROVAL));
        $this->postRepository->shouldReceive('updateFromArray')->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 9
                && $attributes[SocialPostDomainObjectAbstract::STATUS] === SocialPostStatus::CHANGES_REQUESTED->name
                && $attributes[SocialPostDomainObjectAbstract::CHANGE_REQUEST_COMMENT] === 'Plus court'
                && $attributes[SocialPostDomainObjectAbstract::CHANGE_REQUESTED_BY_USER_ID] === 7
                && $attributes[SocialPostDomainObjectAbstract::CHANGE_REQUESTED_AT] instanceof Carbon)
            ->andReturn(new SocialPostDomainObject);

        $this->service->requestChanges(4, 9, 7, " <i>Plus court</i>\r\n");
    }

    public function test_changes_cannot_be_requested_on_a_scheduled_post(): void
    {
        $this->postRepository->shouldReceive('findFirstWhere')->andReturn($this->postIn(SocialPostStatus::SCHEDULED));
        $this->postRepository->shouldNotReceive('updateFromArray');

        $this->expectException(SocialPostNotEditableException::class);

        $this->service->requestChanges(4, 9, 7, 'Plus court');
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

    private function dto(SocialPostSource $source, array $networks, ?int $userId = null, bool $asDraft = false): CreateSocialPostsDTO
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
            asDraft: $asDraft,
        );
    }

    private function fileDto(array $networks, SocialPostSource $source = SocialPostSource::PICHA): CreateSocialPostsDTO
    {
        return new CreateSocialPostsDTO(
            accountId: 3,
            organizerId: 4,
            userId: 7,
            networks: $networks,
            format: SocialPostFormat::IMAGE,
            caption: 'Halloween',
            media: [new SocialMediaItemDTO('', SocialMediaType::IMAGE, fileId: 42)],
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
