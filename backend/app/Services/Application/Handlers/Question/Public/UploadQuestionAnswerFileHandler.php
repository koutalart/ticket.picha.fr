<?php

namespace HiEvents\Services\Application\Handlers\Question\Public;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QuestionDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\InvalidQuestionAnswerFileException;
use HiEvents\Exceptions\QuestionAnswerFileUploadException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\DTO\UploadQuestionAnswerFileDTO;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class UploadQuestionAnswerFileHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly QuestionRepositoryInterface $questionRepository,
        private readonly QuestionAnswerFileService $questionAnswerFileService,
        private readonly CheckoutSessionManagementService $sessionManagementService,
    ) {}

    /**
     * @throws ResourceConflictException
     * @throws InvalidQuestionAnswerFileException
     * @throws QuestionAnswerFileUploadException
     */
    public function handle(UploadQuestionAnswerFileDTO $dto): QuestionAnswerFileDomainObject
    {
        $order = $this->getOrder($dto);
        $question = $this->getQuestion($dto);

        return $this->questionAnswerFileService->store(
            file: $dto->file,
            eventId: $dto->eventId,
            orderId: $order->getId(),
            questionId: $question->getId(),
        );
    }

    /**
     * @throws ResourceConflictException
     */
    private function getOrder(UploadQuestionAnswerFileDTO $dto): OrderDomainObject
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::SHORT_ID => $dto->orderShortId,
            OrderDomainObjectAbstract::EVENT_ID => $dto->eventId,
        ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        if ($order->getSessionId() === null
            || ! $this->sessionManagementService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(
                __('Sorry, we could not verify your session. Please restart your order.')
            );
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name || $order->getEmail() !== null) {
            throw new ResourceConflictException(__('This order has already been processed'));
        }

        if ($order->getReservedUntil() !== null && Carbon::parse($order->getReservedUntil())->isPast()) {
            throw new ResourceConflictException(__('This order has expired'));
        }

        return $order;
    }

    /**
     * @throws InvalidQuestionAnswerFileException
     */
    private function getQuestion(UploadQuestionAnswerFileDTO $dto): QuestionDomainObject
    {
        /** @var QuestionDomainObject|null $question */
        $question = $this->questionRepository->findFirstWhere([
            QuestionDomainObjectAbstract::ID => $dto->questionId,
            QuestionDomainObjectAbstract::EVENT_ID => $dto->eventId,
        ]);

        if ($question === null || ! $question->isFileQuestion()) {
            throw new InvalidQuestionAnswerFileException(__('This question does not accept files.'));
        }

        return $question;
    }
}
