<?php

namespace Tests\Unit\Services\Domain\Question;

use Carbon\Carbon;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\Exceptions\InvalidQuestionAnswerFileException;
use HiEvents\Exceptions\QuestionAnswerFileUploadException;
use HiEvents\Repository\Interfaces\QuestionAnswerFileRepositoryInterface;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class QuestionAnswerFileServiceTest extends TestCase
{
    private QuestionAnswerFileRepositoryInterface|MockInterface $repository;

    private FilesystemManager|MockInterface $filesystemManager;

    private Filesystem|MockInterface $disk;

    private ConfigRepository|MockInterface $config;

    private QuestionAnswerFileService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(QuestionAnswerFileRepositoryInterface::class);
        $this->filesystemManager = Mockery::mock(FilesystemManager::class);
        $this->disk = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(ConfigRepository::class);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->byDefault();

        $this->service = new QuestionAnswerFileService(
            $this->repository,
            $this->filesystemManager,
            $this->config,
            $logger,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_store_saves_the_file_on_the_private_disk(): void
    {
        $upload = UploadedFile::fake()->create('Mon CV.pdf', 120, 'application/pdf');

        $this->config->shouldReceive('get')->with('filesystems.private', 'local')->andReturn('s3-private');
        $this->filesystemManager->shouldReceive('disk')->once()->with('s3-private')->andReturn($this->disk);
        $this->disk->shouldReceive('putFileAs')
            ->once()
            ->withArgs(function (string $directory, $file, string $name) use ($upload) {
                return $directory === 'question-files/3'
                    && $file === $upload
                    && preg_match('/^[A-Za-z0-9]{40}\.pdf$/', $name) === 1;
            })
            ->andReturnUsing(fn (string $directory, $file, string $name) => $directory.'/'.$name);

        $this->repository->shouldReceive('create')
            ->once()
            ->withArgs(function (array $attributes) {
                return $attributes['disk'] === 's3-private'
                    && str_starts_with($attributes['path'], 'question-files/3/')
                    && $attributes['original_filename'] === 'Mon CV.pdf'
                    && $attributes['mime_type'] === 'application/pdf'
                    && $attributes['event_id'] === 3
                    && $attributes['order_id'] === 5
                    && $attributes['question_id'] === 8;
            })
            ->andReturn((new QuestionAnswerFileDomainObject)->setId(1));

        $file = $this->service->store($upload, 3, 5, 8);

        $this->assertSame(1, $file->getId());
    }

    public function test_store_never_uses_the_public_disk(): void
    {
        $upload = UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf');

        $this->config->shouldReceive('get')->with('filesystems.private', 'local')->andReturn('local');
        $this->filesystemManager->shouldReceive('disk')->once()->with('local')->andReturn($this->disk);
        $this->disk->shouldReceive('putFileAs')->andReturn(false);
        $this->repository->shouldNotReceive('create');

        $this->expectException(QuestionAnswerFileUploadException::class);

        $this->service->store($upload, 3, 5, 8);
    }

    public function test_get_unattached_file_for_answer_rejects_a_file_from_another_order_or_question(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'id' => 12,
                'order_id' => 5,
                'question_id' => 8,
                ['question_answer_id', 'null', null],
            ])
            ->andReturnNull();

        $this->expectException(InvalidQuestionAnswerFileException::class);

        $this->service->getUnattachedFileForAnswer(5, 8, 12);
    }

    public function test_get_unattached_file_for_answer_rejects_non_numeric_ids(): void
    {
        $this->repository->shouldNotReceive('findFirstWhere');

        $this->expectException(InvalidQuestionAnswerFileException::class);

        $this->service->getUnattachedFileForAnswer(5, 8, ['file_id' => 12]);
    }

    public function test_delete_files_removes_stored_files_and_rows(): void
    {
        $files = new Collection([
            (new QuestionAnswerFileDomainObject)->setId(1)->setDisk('local')->setPath('question-files/3/a.pdf'),
            (new QuestionAnswerFileDomainObject)->setId(2)->setDisk('local')->setPath('question-files/3/b.pdf'),
        ]);

        $this->filesystemManager->shouldReceive('disk')->twice()->with('local')->andReturn($this->disk);
        $this->disk->shouldReceive('delete')->once()->with('question-files/3/a.pdf')->andReturnTrue();
        $this->disk->shouldReceive('delete')->once()->with('question-files/3/b.pdf')->andReturnTrue();
        $this->repository->shouldReceive('forceDeleteByIds')->once()->with([1, 2])->andReturn(2);

        $this->assertSame(2, $this->service->deleteFiles($files));
    }

    public function test_purge_orphaned_files_deletes_what_the_repository_returns(): void
    {
        $cutoff = Carbon::parse('2026-10-18 10:00:00');
        $file = (new QuestionAnswerFileDomainObject)->setId(9)->setDisk('local')->setPath('question-files/1/x.pdf');

        $this->repository->shouldReceive('findFilesToPurge')->once()->with($cutoff)->andReturn(new Collection([$file]));
        $this->filesystemManager->shouldReceive('disk')->with('local')->andReturn($this->disk);
        $this->disk->shouldReceive('delete')->once()->with('question-files/1/x.pdf')->andReturnTrue();
        $this->repository->shouldReceive('forceDeleteByIds')->once()->with([9])->andReturn(1);

        $this->assertSame(1, $this->service->purgeOrphanedFiles($cutoff));
    }

    public function test_purge_does_nothing_when_there_is_nothing_to_delete(): void
    {
        $this->repository->shouldReceive('findFilesToPurge')->once()->andReturn(new Collection);
        $this->repository->shouldNotReceive('forceDeleteByIds');
        $this->filesystemManager->shouldNotReceive('disk');

        $this->assertSame(0, $this->service->purgeOrphanedFiles(Carbon::now()));
    }

    public function test_build_answer_contains_the_file_id_and_original_name(): void
    {
        $file = (new QuestionAnswerFileDomainObject)->setId(4)->setOriginalFilename('cv.pdf');

        $this->assertSame(['file_id' => 4, 'name' => 'cv.pdf'], $this->service->buildAnswer($file));
    }
}
