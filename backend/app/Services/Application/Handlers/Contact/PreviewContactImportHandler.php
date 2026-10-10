<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Exceptions\ContactImportException;
use HiEvents\Services\Domain\Contact\ContactFileReader;
use HiEvents\Services\Domain\Contact\ContactImportService;
use Illuminate\Http\UploadedFile;

class PreviewContactImportHandler
{
    public function __construct(
        private readonly ContactImportFileStore $fileStore,
        private readonly ContactFileReader $fileReader,
        private readonly ContactImportService $importService,
    ) {}

    /**
     * @throws ContactImportException
     */
    public function handle(int $organizerId, UploadedFile $file): array
    {
        $token = $this->fileStore->store($organizerId, $file);

        try {
            ['headers' => $headers, 'rows' => $rows] = $this->fileReader->read($this->fileStore->path($organizerId, $token));
        } catch (ContactImportException $exception) {
            $this->fileStore->delete($organizerId, $token);
            throw $exception;
        }

        return [
            'token' => $token,
            'file_name' => $file->getClientOriginalName(),
            'headers' => $headers,
            'sample_rows' => array_slice($rows, 0, 5),
            'row_count' => count($rows),
            'suggested_mapping' => $this->importService->suggestMapping($headers),
        ];
    }
}
