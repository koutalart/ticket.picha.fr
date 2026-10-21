<?php

namespace HiEvents\Services\Application\Handlers\Question;

use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadQuestionAnswerFileHandler
{
    public function __construct(
        private readonly QuestionAnswerFileService $questionAnswerFileService,
    ) {}

    public function handle(int $fileId): StreamedResponse
    {
        return $this->questionAnswerFileService->download($fileId);
    }
}
