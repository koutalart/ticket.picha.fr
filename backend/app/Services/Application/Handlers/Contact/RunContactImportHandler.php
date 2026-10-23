<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Exceptions\ContactImportException;
use HiEvents\Services\Domain\Contact\ContactFileReader;
use HiEvents\Services\Domain\Contact\ContactImportService;
use HiEvents\Services\Domain\Contact\DTO\ContactImportOptionsDTO;
use HiEvents\Services\Domain\Contact\DTO\ContactImportResultDTO;

class RunContactImportHandler
{
    public function __construct(
        private readonly ContactImportFileStore $fileStore,
        private readonly ContactFileReader $fileReader,
        private readonly ContactImportService $importService,
    ) {}

    /**
     * @throws ContactImportException
     */
    public function handle(string $token, ContactImportOptionsDTO $options, bool $dryRun): ContactImportResultDTO
    {
        ['rows' => $rows] = $this->fileReader->read($this->fileStore->path($options->organizerId, $token));

        $result = $this->importService->import($rows, $options, $dryRun);

        if (! $dryRun) {
            $this->fileStore->delete($options->organizerId, $token);
        }

        return $result;
    }
}
