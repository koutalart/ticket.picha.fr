<?php

namespace Tests\Unit\Services\Application\Handlers\Question;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\InvalidQuestionAnswerFileException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\DTO\UploadQuestionAnswerFileDTO;
use HiEvents\Services\Application\Handlers\Question\Public\UploadQuestionAnswerFileHandler;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class UploadQuestionAnswerFileHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;

    private QuestionRepositoryInterface|MockInterface $questionRepository;

    private QuestionAnswerFileService|MockInterface $fileService;

    private CheckoutSessionManagementService|MockInterface $sessionService;

    private UploadQuestionAnswerFileHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $this->fileService = Mockery::mock(QuestionAnswerFileService::class);
        $this->sessionService = Mockery::mock(CheckoutSessionManagementService::class);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true)->byDefault();

        $this->handler = new UploadQuestionAnswerFileHandler(
            $this->orderRepository,
            $this->questionRepository,
            $this->fileService,
            $this->sessionService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_stores_the_file_for_a_reserved_order_and_a_file_question(): void
    {
        $dto = $this->createDto();
        $stored = (new QuestionAnswerFileDomainObject)->setId(55);

        $this->orderRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['short_id' => 'o_abc', 'event_id' => 3])
            ->andReturn($this->createOrder());
        $this->questionRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 8, 'event_id' => 3])
            ->andReturn($this->createQuestion(QuestionTypeEnum::FILE));
        $this->fileService->shouldReceive('store')
            ->once()
            ->with($dto->file, 3, 10, 8)
            ->andReturn($stored);

        $this->assertSame($stored, $this->handler->handle($dto));
    }

    public function test_rejects_a_question_that_is_not_a_file_question(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($this->createOrder());
        $this->questionRepository->shouldReceive('findFirstWhere')->andReturn($this->createQuestion(QuestionTypeEnum::SINGLE_LINE_TEXT));
        $this->fileService->shouldNotReceive('store');

        $this->expectException(InvalidQuestionAnswerFileException::class);

        $this->handler->handle($this->createDto());
    }

    public function test_rejects_a_question_from_another_event(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($this->createOrder());
        $this->questionRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 8, 'event_id' => 3])
            ->andReturnNull();
        $this->fileService->shouldNotReceive('store');

        $this->expectException(InvalidQuestionAnswerFileException::class);

        $this->handler->handle($this->createDto());
    }

    public function test_rejects_an_order_from_another_event(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->with(['short_id' => 'o_abc', 'event_id' => 3])
            ->andReturnNull();
        $this->fileService->shouldNotReceive('store');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle($this->createDto());
    }

    public function test_rejects_a_completed_order(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->createOrder(OrderStatus::COMPLETED));
        $this->fileService->shouldNotReceive('store');

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->createDto());
    }

    public function test_rejects_an_order_whose_answers_were_already_submitted(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->createOrder()->setEmail('john@example.com'));
        $this->fileService->shouldNotReceive('store');

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->createDto());
    }

    public function test_rejects_an_expired_order(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->createOrder()->setReservedUntil(Carbon::now()->subMinute()->toDateTimeString()));
        $this->fileService->shouldNotReceive('store');

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->createDto());
    }

    public function test_rejects_another_session(): void
    {
        $this->sessionService->shouldReceive('verifySession')->andReturn(false);
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($this->createOrder());
        $this->fileService->shouldNotReceive('store');

        $this->expectException(UnauthorizedException::class);

        $this->handler->handle($this->createDto());
    }

    private function createDto(): UploadQuestionAnswerFileDTO
    {
        return new UploadQuestionAnswerFileDTO(
            eventId: 3,
            orderShortId: 'o_abc',
            questionId: 8,
            file: UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        );
    }

    private function createOrder(OrderStatus $status = OrderStatus::RESERVED): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(10)
            ->setEventId(3)
            ->setStatus($status->name)
            ->setEmail(null)
            ->setSessionId('session')
            ->setReservedUntil(Carbon::now()->addMinutes(10)->toDateTimeString());
    }

    private function createQuestion(QuestionTypeEnum $type): QuestionDomainObject
    {
        return (new QuestionDomainObject)
            ->setId(8)
            ->setEventId(3)
            ->setType($type->name);
    }
}
