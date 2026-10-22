<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Exceptions\ContactImportException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ContactImportFileStore
{
    private Filesystem $disk;

    public function __construct(FilesystemManager $filesystemManager)
    {
        $this->disk = $filesystemManager->disk('local');
    }

    public function store(int $organizerId, UploadedFile $file): string
    {
        $this->pruneOldFiles($organizerId);

        $token = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');

        $this->disk->putFileAs($this->directory($organizerId), $file, "{$token}.{$extension}");

        return $token;
    }

    /**
     * @throws ContactImportException
     */
    public function path(int $organizerId, string $token): string
    {
        if (! Str::isUuid($token)) {
            throw new ContactImportException(__('This import has expired. Upload the file again.'));
        }

        foreach ($this->disk->files($this->directory($organizerId)) as $file) {
            if (str_starts_with(basename($file), $token.'.')) {
                return $this->disk->path($file);
            }
        }

        throw new ContactImportException(__('This import has expired. Upload the file again.'));
    }

    public function delete(int $organizerId, string $token): void
    {
        foreach ($this->disk->files($this->directory($organizerId)) as $file) {
            if (str_starts_with(basename($file), $token.'.')) {
                $this->disk->delete($file);
            }
        }
    }

    private function pruneOldFiles(int $organizerId): void
    {
        $limit = now()->subDay()->getTimestamp();

        foreach ($this->disk->files($this->directory($organizerId)) as $file) {
            if ($this->disk->lastModified($file) < $limit) {
                $this->disk->delete($file);
            }
        }
    }

    private function directory(int $organizerId): string
    {
        return "contact-imports/{$organizerId}";
    }
}
