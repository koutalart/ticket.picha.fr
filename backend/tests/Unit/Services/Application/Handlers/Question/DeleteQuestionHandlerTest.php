<?php

namespace Tests\Unit\Services\Application\Handlers\Question;

use HiEvents\DomainObjects\QuestionAnswerDomainObject;
use HiEvents\Exceptions\CannotDeleteEntityException;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\DeleteQuestionHandler;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DeleteQuestionHandlerTest extends TestCase
{
    private QuestionRepositoryInterface|MockInterface $questionRepository;

    private QuestionAnswerRepositoryInterface|MockInterface $questionAnswerRepository;

    private QuestionAnswerFileService|MockInterface $fileService;

    private DeleteQuestionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $this->questionAnswerRepository = Mockery::mock(QuestionAnswerRepositoryInterface::class);
        $this->fileService = Mockery::mock(QuestionAnswerFileService::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->handler = new DeleteQuestionHandler(
            $this->questionRepository,
            $this->questionAnswerRepository,
            $databaseManager,
            $this->fileService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_deleting_a_question_deletes_its_uploaded_files(): void
    {
        $this->questionAnswerRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->questionAnswerRepository->shouldReceive('deleteWhere')->once()->with(['question_id' => 8]);
        $this->fileService->shouldReceive('deleteFilesForQuestion')->once()->with(8)->andReturn(2);
        $this->questionRepository->shouldReceive('deleteWhere')->once()->with(['id' => 8, 'event_id' => 3]);

        $this->handler->handle(8, 3);

        $this->assertTrue(true);
    }

    public function test_question_with_answers_is_not_deleted_and_files_are_kept(): void
    {
        $this->questionAnswerRepository->shouldReceive('findWhere')
            ->andReturn(new Collection([new QuestionAnswerDomainObject]));
        $this->fileService->shouldNotReceive('deleteFilesForQuestion');

        $this->expectException(CannotDeleteEntityException::class);

        $this->handler->handle(8, 3);
    }
}
