<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use Illuminate\Console\Command;

class PurgeQuestionAnswerFilesCommand extends Command
{
    public const UNATTACHED_FILE_TTL_HOURS = 24;

    protected $signature = 'question-files:purge';

    protected $description = 'Delete uploaded question files never attached to an answer after 24 hours, or whose order or answer was deleted';

    public function __construct(
        private readonly QuestionAnswerFileService $questionAnswerFileService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $deleted = $this->questionAnswerFileService->purgeOrphanedFiles(
            now()->subHours(self::UNATTACHED_FILE_TTL_HOURS)
        );

        $this->info(sprintf('Deleted %d question file(s).', $deleted));

        return self::SUCCESS;
    }
}
