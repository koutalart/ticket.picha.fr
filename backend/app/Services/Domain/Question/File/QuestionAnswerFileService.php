<?php

namespace HiEvents\Services\Domain\Question\File;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\Generated\QuestionAnswerFileDomainObjectAbstract;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\Exceptions\InvalidQuestionAnswerFileException;
use HiEvents\Exceptions\QuestionAnswerFileUploadException;
use HiEvents\Repository\Interfaces\QuestionAnswerFileRepositoryInterface;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class QuestionAnswerFileService
{
    private const DIRECTORY = 'question-files';

    public function __construct(
        private readonly QuestionAnswerFileRepositoryInterface $questionAnswerFileRepository,
        private readonly FilesystemManager $filesystemManager,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws QuestionAnswerFileUploadException
     */
    public function store(UploadedFile $file, int $eventId, int $orderId, int $questionId): QuestionAnswerFileDomainObject
    {
        $disk = $this->config->get('filesystems.private', 'local');
        $directory = self::DIRECTORY.'/'.$eventId;
        $filename = Str::random(40).'.pdf';

        $path = $this->filesystemManager->disk($disk)->putFileAs($directory, $file, $filename);

        if ($path === false) {
            throw new QuestionAnswerFileUploadException(__('The file could not be saved. Please try again.'));
        }

        return $this->questionAnswerFileRepository->create([
            QuestionAnswerFileDomainObjectAbstract::EVENT_ID => $eventId,
            QuestionAnswerFileDomainObjectAbstract::ORDER_ID => $orderId,
            QuestionAnswerFileDomainObjectAbstract::QUESTION_ID => $questionId,
            QuestionAnswerFileDomainObjectAbstract::DISK => $disk,
            QuestionAnswerFileDomainObjectAbstract::PATH => $path,
            QuestionAnswerFileDomainObjectAbstract::ORIGINAL_FILENAME => $this->sanitizeFilename($file->getClientOriginalName()),
            QuestionAnswerFileDomainObjectAbstract::MIME_TYPE => 'application/pdf',
            QuestionAnswerFileDomainObjectAbstract::SIZE => (int) $file->getSize(),
        ]);
    }

    /**
     * @throws InvalidQuestionAnswerFileException
     */
    public function getUnattachedFileForAnswer(int $orderId, int $questionId, mixed $fileId): QuestionAnswerFileDomainObject
    {
        $file = null;

        if (is_int($fileId) || (is_string($fileId) && ctype_digit($fileId))) {
            $file = $this->questionAnswerFileRepository->findFirstWhere([
                QuestionAnswerFileDomainObjectAbstract::ID => (int) $fileId,
                QuestionAnswerFileDomainObjectAbstract::ORDER_ID => $orderId,
                QuestionAnswerFileDomainObjectAbstract::QUESTION_ID => $questionId,
                [QuestionAnswerFileDomainObjectAbstract::QUESTION_ANSWER_ID, 'null', null],
            ]);
        }

        if ($file === null) {
            throw new InvalidQuestionAnswerFileException(
                __('The uploaded file is invalid or has expired. Please upload it again.')
            );
        }

        return $file;
    }

    /**
     * @return Collection<QuestionAnswerFileDomainObject>
     */
    public function getUnattachedFilesForOrder(int $orderId): Collection
    {
        return $this->questionAnswerFileRepository->findWhere([
            QuestionAnswerFileDomainObjectAbstract::ORDER_ID => $orderId,
            [QuestionAnswerFileDomainObjectAbstract::QUESTION_ANSWER_ID, 'null', null],
        ]);
    }

    public function buildAnswer(QuestionAnswerFileDomainObject $file): array
    {
        return [
            'file_id' => $file->getId(),
            'name' => $file->getOriginalFilename(),
        ];
    }

    public function attachToAnswer(int $fileId, int $questionAnswerId): void
    {
        $this->questionAnswerFileRepository->updateWhere(
            attributes: [QuestionAnswerFileDomainObjectAbstract::QUESTION_ANSWER_ID => $questionAnswerId],
            where: [QuestionAnswerFileDomainObjectAbstract::ID => $fileId],
        );
    }

    public function download(int $fileId): StreamedResponse
    {
        /** @var QuestionAnswerFileDomainObject|null $file */
        $file = $this->questionAnswerFileRepository->findFirstWhere([
            QuestionAnswerFileDomainObjectAbstract::ID => $fileId,
        ]);

        $disk = $file ? $this->filesystemManager->disk($file->getDisk()) : null;

        if ($file === null || ! $disk->exists($file->getPath())) {
            throw new ResourceNotFoundException(__('File not found'));
        }

        return $disk->download($file->getPath(), $file->getOriginalFilename(), [
            'Content-Type' => $file->getMimeType(),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  Collection<QuestionAnswerFileDomainObject>  $files
     */
    public function deleteFiles(Collection $files): int
    {
        if ($files->isEmpty()) {
            return 0;
        }

        foreach ($files as $file) {
            $this->filesystemManager->disk($file->getDisk())->delete($file->getPath());
        }

        $ids = $files->map(fn (QuestionAnswerFileDomainObject $file) => $file->getId())->values()->all();

        $this->logger->info('Deleted question answer files', ['file_ids' => $ids]);

        return $this->questionAnswerFileRepository->forceDeleteByIds($ids);
    }

    public function purgeOrphanedFiles(CarbonInterface $unattachedBefore): int
    {
        return $this->deleteFiles($this->questionAnswerFileRepository->findFilesToPurge($unattachedBefore));
    }

    public function deleteFilesForQuestion(int $questionId): int
    {
        return $this->deleteFiles($this->questionAnswerFileRepository->findWhere([
            QuestionAnswerFileDomainObjectAbstract::QUESTION_ID => $questionId,
        ]));
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '', basename($filename)) ?? '';
        $filename = trim(Str::limit($filename, 200, ''));

        if ($filename === '') {
            return 'document.pdf';
        }

        return Str::endsWith(Str::lower($filename), '.pdf') ? $filename : $filename.'.pdf';
    }
}
