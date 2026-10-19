<?php

namespace HiEvents\Http\Actions\Questions\Public;

use HiEvents\Exceptions\InvalidQuestionAnswerFileException;
use HiEvents\Exceptions\QuestionAnswerFileUploadException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Questions\UploadQuestionAnswerFileRequest;
use HiEvents\Resources\Question\QuestionAnswerFileResourcePublic;
use HiEvents\Services\Application\Handlers\Question\DTO\UploadQuestionAnswerFileDTO;
use HiEvents\Services\Application\Handlers\Question\Public\UploadQuestionAnswerFileHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class UploadQuestionAnswerFilePublicAction extends BaseAction
{
    public function __construct(
        private readonly UploadQuestionAnswerFileHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UploadQuestionAnswerFileRequest $request, int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $file = $this->handler->handle(new UploadQuestionAnswerFileDTO(
                eventId: $eventId,
                orderShortId: $orderShortId,
                questionId: (int) $request->validated('question_id'),
                file: $request->file('file'),
            ));
        } catch (InvalidQuestionAnswerFileException $exception) {
            throw ValidationException::withMessages(['question_id' => $exception->getMessage()]);
        } catch (QuestionAnswerFileUploadException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } catch (ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->resourceResponse(
            resource: QuestionAnswerFileResourcePublic::class,
            data: $file,
            statusCode: Response::HTTP_CREATED,
        );
    }
}
