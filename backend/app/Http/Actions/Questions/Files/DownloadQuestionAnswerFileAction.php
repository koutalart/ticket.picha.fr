<?php

namespace HiEvents\Http\Actions\Questions\Files;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Question\DownloadQuestionAnswerFileHandler;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Access is granted by the temporary signed URL, generated only in organizer resources and exports.
 */
class DownloadQuestionAnswerFileAction extends BaseAction
{
    public function __construct(
        private readonly DownloadQuestionAnswerFileHandler $handler,
    ) {}

    public function __invoke(int $fileId): StreamedResponse
    {
        return $this->handler->handle($fileId);
    }
}
