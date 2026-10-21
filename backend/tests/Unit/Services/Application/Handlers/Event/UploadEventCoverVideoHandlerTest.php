<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Exceptions\CoverVideoUploadException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Infrastructure\Image\DTO\ImageStorageResponseDTO;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Mockery as m;
use Tests\TestCase;

class UploadEventCoverVideoHandlerTest extends TestCase
{
    private EventRepositoryInterface $eventRepository;

    private ImageRepositoryInterface $imageRepository;

    private ImageStorageService $storage;

    private UploadEventCoverVideoHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->imageRepository = m::mock(ImageRepositoryInterface::class);
        $this->storage = m::mock(ImageStorageService::class);
        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->handler = new UploadEventCoverVideoHandler(
            $this->eventRepository,
            $this->imageRepository,
            $this->storage,
            $databaseManager,
        );
    }

    private function dto(bool $mobile = false): UploadEventCoverVideoDTO
    {
        return new UploadEventCoverVideoDTO(
            eventId: 12,
            accountId: 6,
            video: UploadedFile::fake()->create('teaser.mp4', 2048, 'video/mp4'),
            mobile: $mobile,
        );
    }

    public function test_replaces_the_previous_video_and_stores_the_new_one(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 12, 'account_id' => 6])
            ->andReturn(new EventDomainObject);

        $this->storage->shouldReceive('store')
            ->with(m::type(UploadedFile::class), ImageType::EVENT_COVER_VIDEO->name)
            ->andReturn(new ImageStorageResponseDTO('teaser-abcde.mp4', 'public', 'event_cover_video/teaser-abcde.mp4', 2097152, 'video/mp4'));

        $this->imageRepository->shouldReceive('deleteWhere')->once()->with([
            'entity_id' => 12,
            'entity_type' => EventDomainObject::class,
            'type' => 'EVENT_COVER_VIDEO',
        ])->andReturn(1);

        $created = new ImageDomainObject;
        $this->imageRepository->shouldReceive('create')->once()
            ->with(m::on(fn (array $data) => $data['type'] === 'EVENT_COVER_VIDEO'
                && $data['entity_id'] === 12
                && $data['account_id'] === 6
                && $data['mime_type'] === 'video/mp4'
                && $data['path'] === 'event_cover_video/teaser-abcde.mp4'))
            ->andReturn($created);

        $this->assertSame($created, $this->handler->handle($this->dto()));
    }

    public function test_stores_the_mobile_video_without_touching_the_main_one(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 12, 'account_id' => 6])
            ->andReturn(new EventDomainObject);

        $this->storage->shouldReceive('store')
            ->with(m::type(UploadedFile::class), ImageType::EVENT_COVER_VIDEO_MOBILE->name)
            ->andReturn(new ImageStorageResponseDTO('teaser-720.mp4', 'public', 'event_cover_video_mobile/teaser-720.mp4', 1048576, 'video/mp4'));

        $this->imageRepository->shouldReceive('deleteWhere')->once()->with([
            'entity_id' => 12,
            'entity_type' => EventDomainObject::class,
            'type' => 'EVENT_COVER_VIDEO_MOBILE',
        ])->andReturn(0);

        $created = new ImageDomainObject;
        $this->imageRepository->shouldReceive('create')->once()
            ->with(m::on(fn (array $data) => $data['type'] === 'EVENT_COVER_VIDEO_MOBILE'
                && $data['entity_id'] === 12
                && $data['path'] === 'event_cover_video_mobile/teaser-720.mp4'))
            ->andReturn($created);

        $this->assertSame($created, $this->handler->handle($this->dto(mobile: true)));
    }

    public function test_rejects_an_event_from_another_account(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->storage->shouldNotReceive('store');
        $this->imageRepository->shouldNotReceive('create');

        $this->expectException(CoverVideoUploadException::class);

        $this->handler->handle($this->dto());
    }

    public function test_does_not_touch_the_current_video_when_storage_fails(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $this->storage->shouldReceive('store')->andThrow(new CouldNotUploadImageException('disk full'));
        $this->imageRepository->shouldNotReceive('deleteWhere');
        $this->imageRepository->shouldNotReceive('create');

        $this->expectException(CoverVideoUploadException::class);

        $this->handler->handle($this->dto());
    }
}
