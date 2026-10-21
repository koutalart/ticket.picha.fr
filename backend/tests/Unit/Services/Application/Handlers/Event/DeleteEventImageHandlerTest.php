<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\HomepageBackgroundType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DeleteEventImageHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\DeleteEventImageDTO;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class DeleteEventImageHandlerTest extends TestCase
{
    private ImageRepositoryInterface $imageRepository;

    private EventSettingsRepositoryInterface $eventSettingsRepository;

    private DeleteEventImageHandler $handler;

    private array $where = [
        'entity_id' => 12,
        'entity_type' => EventDomainObject::class,
        'id' => 40,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageRepository = m::mock(ImageRepositoryInterface::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->handler = new DeleteEventImageHandler(
            $this->imageRepository,
            $this->eventSettingsRepository,
            $databaseManager,
        );
    }

    private function image(string $type): ImageDomainObject
    {
        return (new ImageDomainObject)->setId(40)->setType($type);
    }

    public function test_deletes_the_mobile_cover_video_without_touching_the_background(): void
    {
        $this->imageRepository->shouldReceive('findFirstWhere')->with($this->where)
            ->andReturn($this->image('EVENT_COVER_VIDEO_MOBILE'));
        $this->eventSettingsRepository->shouldNotReceive('findFirstWhere');
        $this->eventSettingsRepository->shouldNotReceive('updateWhere');
        $this->imageRepository->shouldReceive('deleteWhere')->once()->with($this->where)->andReturn(1);

        $this->handler->handle(new DeleteEventImageDTO(eventId: 12, imageId: 40));
    }

    public function test_deletes_the_main_cover_video(): void
    {
        $this->imageRepository->shouldReceive('findFirstWhere')->with($this->where)
            ->andReturn($this->image('EVENT_COVER_VIDEO'));
        $this->eventSettingsRepository->shouldNotReceive('updateWhere');
        $this->imageRepository->shouldReceive('deleteWhere')->once()->with($this->where)->andReturn(1);

        $this->handler->handle(new DeleteEventImageDTO(eventId: 12, imageId: 40));
    }

    public function test_resets_a_mirrored_background_when_the_cover_image_is_deleted(): void
    {
        $this->imageRepository->shouldReceive('findFirstWhere')->with($this->where)
            ->andReturn($this->image('EVENT_COVER'));
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->with(['event_id' => 12])
            ->andReturn((new EventSettingDomainObject)->setHomepageBackgroundType(HomepageBackgroundType::MIRROR_COVER_IMAGE->name));
        $this->eventSettingsRepository->shouldReceive('updateWhere')->once()->with(
            ['homepage_background_type' => HomepageBackgroundType::COLOR->name],
            ['event_id' => 12],
        );
        $this->imageRepository->shouldReceive('deleteWhere')->once()->with($this->where)->andReturn(1);

        $this->handler->handle(new DeleteEventImageDTO(eventId: 12, imageId: 40));
    }

    public function test_ignores_an_image_from_another_event(): void
    {
        $this->imageRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->imageRepository->shouldNotReceive('deleteWhere');

        $this->handler->handle(new DeleteEventImageDTO(eventId: 12, imageId: 40));
    }
}
