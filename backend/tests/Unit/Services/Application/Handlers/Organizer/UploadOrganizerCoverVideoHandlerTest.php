<?php

namespace Tests\Unit\Services\Application\Handlers\Organizer;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\CoverVideoUploadException;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\CoverVideo\DTO\UploadOrganizerCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Organizer\CoverVideo\UploadOrganizerCoverVideoHandler;
use HiEvents\Services\Infrastructure\Image\DTO\ImageStorageResponseDTO;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Mockery as m;
use Tests\TestCase;

class UploadOrganizerCoverVideoHandlerTest extends TestCase
{
    private OrganizerRepositoryInterface $organizerRepository;

    private ImageRepositoryInterface $imageRepository;

    private ImageStorageService $storage;

    private UploadOrganizerCoverVideoHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $this->imageRepository = m::mock(ImageRepositoryInterface::class);
        $this->storage = m::mock(ImageStorageService::class);
        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->handler = new UploadOrganizerCoverVideoHandler(
            $this->organizerRepository,
            $this->imageRepository,
            $this->storage,
            $databaseManager,
        );
    }

    private function dto(): UploadOrganizerCoverVideoDTO
    {
        return new UploadOrganizerCoverVideoDTO(
            organizerId: 9,
            accountId: 6,
            video: UploadedFile::fake()->create('teaser.mp4', 2048, 'video/mp4'),
        );
    }

    public function test_replaces_the_previous_video_and_stores_the_new_one(): void
    {
        $this->organizerRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 9, 'account_id' => 6])
            ->andReturn(new OrganizerDomainObject);

        $this->storage->shouldReceive('store')
            ->with(m::type(UploadedFile::class), ImageType::ORGANIZER_COVER_VIDEO->name)
            ->andReturn(new ImageStorageResponseDTO('teaser-abcde.mp4', 'public', 'organizer_cover_video/teaser-abcde.mp4', 2097152, 'video/mp4'));

        $this->imageRepository->shouldReceive('deleteWhere')->once()->with([
            'entity_id' => 9,
            'entity_type' => OrganizerDomainObject::class,
            'type' => 'ORGANIZER_COVER_VIDEO',
        ])->andReturn(1);

        $created = new ImageDomainObject;
        $this->imageRepository->shouldReceive('create')->once()
            ->with(m::on(fn (array $data) => $data['type'] === 'ORGANIZER_COVER_VIDEO'
                && $data['entity_id'] === 9
                && $data['account_id'] === 6
                && $data['mime_type'] === 'video/mp4'
                && $data['path'] === 'organizer_cover_video/teaser-abcde.mp4'))
            ->andReturn($created);

        $this->assertSame($created, $this->handler->handle($this->dto()));
    }

    public function test_rejects_an_organizer_from_another_account(): void
    {
        $this->organizerRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->storage->shouldNotReceive('store');
        $this->imageRepository->shouldNotReceive('create');

        $this->expectException(CoverVideoUploadException::class);

        $this->handler->handle($this->dto());
    }

    public function test_does_not_touch_the_current_video_when_storage_fails(): void
    {
        $this->organizerRepository->shouldReceive('findFirstWhere')->andReturn(new OrganizerDomainObject);
        $this->storage->shouldReceive('store')->andThrow(new CouldNotUploadImageException('disk full'));
        $this->imageRepository->shouldNotReceive('deleteWhere');
        $this->imageRepository->shouldNotReceive('create');

        $this->expectException(CoverVideoUploadException::class);

        $this->handler->handle($this->dto());
    }
}
