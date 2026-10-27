<?php

namespace Tests\Unit\Services\Domain\Social\Media;

use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\DomainObjects\Status\SocialMediaFileStatus;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Repository\Interfaces\SocialMediaFileRepositoryInterface;
use HiEvents\Services\Domain\Social\Media\Mp4MetadataReader;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use HiEvents\Services\Domain\Social\Media\SocialMediaProbe;
use HiEvents\Services\Domain\Social\Media\SocialMediaRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SocialMediaFileServiceTest extends TestCase
{
    private SocialMediaFileRepositoryInterface|MockInterface $repository;

    private SocialMediaFileService $service;

    private SocialMediaFileDomainObject $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('private-test');
        config(['filesystems.private' => 'private-test']);

        $this->repository = Mockery::mock(SocialMediaFileRepositoryInterface::class);
        $this->repository->shouldReceive('create')->andReturnUsing(function (array $attributes) {
            $this->file = $this->hydrate((new SocialMediaFileDomainObject)->setId(7), $attributes);

            return $this->file;
        });
        $this->repository->shouldReceive('findFirstWhere')->andReturnUsing(fn () => $this->file);
        $this->repository->shouldReceive('updateFromArray')->andReturnUsing(function (int $id, array $attributes) {
            $this->file = $this->hydrate($this->file, $attributes);

            return $this->file;
        });

        $this->service = new SocialMediaFileService(
            $this->repository,
            new SocialMediaRules,
            new SocialMediaProbe(new Mp4MetadataReader),
            app('filesystem'),
            app('config'),
            new NullLogger,
        );
    }

    public function test_refuses_an_unsupported_type_or_a_too_large_video_before_upload(): void
    {
        $this->repository->shouldNotReceive('create');

        foreach ([['image/gif', 1000], ['video/mp4', 101 * 1024 * 1024]] as [$mime, $size]) {
            try {
                $this->service->start(3, 4, 9, 'file', $mime, $size);
                $this->fail('An exception was expected for '.$mime);
            } catch (InvalidSocialMediaFileException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_imports_a_reel_in_chunks_and_keeps_the_original_bytes(): void
    {
        $source = base_path('tests/Fixtures/Social/vertical-9x16.mp4');
        $bytes = file_get_contents($source);

        $this->service->start(3, 4, 9, 'teaser.mp4', 'video/mp4', strlen($bytes));
        $this->service->appendChunk(4, 7, 0, UploadedFile::fake()->createWithContent('chunk', $bytes));
        $file = $this->service->complete(4, 7, SocialPostFormat::REEL);

        $this->assertSame(SocialMediaFileStatus::READY->name, $file->getStatus());
        $this->assertSame('video/mp4', $file->getMimeType());
        $this->assertSame(108, $file->getWidth());
        $this->assertSame(192, $file->getHeight());
        $this->assertSame('avc1', $file->getVideoCodec());
        $this->assertStringStartsWith('social-media/4/', $file->getPath());
        $this->assertSame($bytes, Storage::disk('private-test')->get($file->getPath()));
        $this->assertFalse(Storage::disk('local')->exists('social-uploads/7.part'));
        $this->assertNotNull($file->getPurgeAfter());
    }

    public function test_refuses_a_horizontal_video_for_a_reel_and_discards_it(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/Social/horizontal-16x9-moov-at-end.mp4'));
        $this->repository->shouldReceive('deleteById')->once()->with(7);

        $this->service->start(3, 4, 9, 'landscape.mp4', 'video/mp4', strlen($bytes));
        $this->service->appendChunk(4, 7, 0, UploadedFile::fake()->createWithContent('chunk', $bytes));

        $this->expectException(InvalidSocialMediaFileException::class);
        $this->service->complete(4, 7, SocialPostFormat::REEL);
    }

    public function test_detects_the_real_type_whatever_the_declared_one(): void
    {
        $bytes = 'GIF89a'.str_repeat("\0", 100);
        $this->repository->shouldReceive('deleteById')->once();

        $this->service->start(3, 4, 9, 'fake.jpg', 'image/jpeg', strlen($bytes));
        $this->service->appendChunk(4, 7, 0, UploadedFile::fake()->createWithContent('chunk', $bytes));

        $this->expectException(InvalidSocialMediaFileException::class);
        $this->service->complete(4, 7, null);
    }

    public function test_refuses_a_chunk_out_of_order(): void
    {
        $this->service->start(3, 4, 9, 'teaser.mp4', 'video/mp4', SocialMediaFileService::CHUNK_BYTES + 10);

        $this->expectException(InvalidSocialMediaFileException::class);
        $this->service->appendChunk(4, 7, 1, UploadedFile::fake()->createWithContent('chunk', 'abc'));
    }

    public function test_refuses_to_complete_an_incomplete_upload(): void
    {
        $this->service->start(3, 4, 9, 'teaser.mp4', 'video/mp4', 1000);
        $this->service->appendChunk(4, 7, 0, UploadedFile::fake()->createWithContent('chunk', str_repeat('a', 10)));

        $this->expectException(InvalidSocialMediaFileException::class);
        $this->service->complete(4, 7, null);
    }

    public function test_purge_deletes_the_stored_file_and_its_record(): void
    {
        Storage::disk('private-test')->put('social-media/4/old.mp4', 'video');
        $file = (new SocialMediaFileDomainObject)->setId(12)->setDisk('private-test')->setPath('social-media/4/old.mp4');
        $this->repository->shouldReceive('findDueForPurge')->once()->andReturn(new Collection([$file]));
        $this->repository->shouldReceive('deleteById')->once()->with(12);

        $this->assertSame(1, $this->service->purgeDue());
        $this->assertFalse(Storage::disk('private-test')->exists('social-media/4/old.mp4'));
    }

    private function hydrate(SocialMediaFileDomainObject $file, array $attributes): SocialMediaFileDomainObject
    {
        foreach ($attributes as $key => $value) {
            $setter = 'set'.str_replace('_', '', ucwords($key, '_'));
            $file->{$setter}($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);
        }

        return $file;
    }
}
