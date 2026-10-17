<?php

namespace Tests\Unit\Services\Application\Handlers\Images;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Domain\Image\ImageUploadService;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use Illuminate\Http\UploadedFile;
use Mockery as m;
use Tests\TestCase;

class CreateEventFlyerImageTest extends TestCase
{
    private ImageUploadService $imageUploadService;

    private EventRepositoryInterface $eventRepository;

    private ImageRepositoryInterface $imageRepository;

    private CreateImageHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageUploadService = m::mock(ImageUploadService::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->imageRepository = m::mock(ImageRepositoryInterface::class);

        $this->handler = new CreateImageHandler(
            $this->imageUploadService,
            m::mock(OrganizerRepositoryInterface::class),
            $this->eventRepository,
            $this->imageRepository,
        );
    }

    private function dto(): CreateImageDTO
    {
        return new CreateImageDTO(
            userId: 10,
            accountId: 6,
            image: UploadedFile::fake()->image('affiche.jpg', 1080, 1350),
            imageType: ImageType::EVENT_FLYER,
            entityId: 8,
        );
    }

    public function test_flyer_is_an_event_image_kept_apart_from_the_cover(): void
    {
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_FLYER->getEntityType());
        $this->assertNotSame(ImageType::EVENT_COVER, ImageType::EVENT_FLYER);
    }

    public function test_a_new_flyer_replaces_only_the_previous_flyer(): void
    {
        $this->eventRepository->shouldReceive('findById')->with(8)->andReturn((new EventDomainObject)->setAccountId(6));

        $this->imageRepository->shouldReceive('deleteWhere')->once()->with([
            'entity_id' => 8,
            'entity_type' => EventDomainObject::class,
            'type' => 'EVENT_FLYER',
        ]);

        $created = new ImageDomainObject;
        $this->imageUploadService->shouldReceive('upload')->once()
            ->with(m::type(UploadedFile::class), 8, EventDomainObject::class, 'EVENT_FLYER', 6)
            ->andReturn($created);

        $this->assertSame($created, $this->handler->handle($this->dto()));
    }

    public function test_rejects_a_flyer_for_an_event_of_another_account(): void
    {
        $this->eventRepository->shouldReceive('findById')->with(8)->andReturn((new EventDomainObject)->setAccountId(3));
        $this->imageRepository->shouldNotReceive('deleteWhere');
        $this->imageUploadService->shouldNotReceive('upload');

        $this->expectException(CouldNotUploadImageException::class);

        $this->handler->handle($this->dto());
    }
}
