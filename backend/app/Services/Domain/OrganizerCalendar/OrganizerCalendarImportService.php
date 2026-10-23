<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Generated\OrganizerCalendarDomainObjectAbstract as Calendar;
use HiEvents\DomainObjects\Generated\OrganizerCalendarMediaDomainObjectAbstract as Media;
use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\DomainObjects\OrganizerCalendarMediaDomainObject;
use HiEvents\Exceptions\OrganizerCalendarImportException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerCalendarMediaRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerCalendarRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\ImportedOrganizerCalendarDTO;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\ImportOrganizerCalendarDTO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\File;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Imports a calendar folder: calendar.json (required), index.html (the page, optional) and the media files
 * the JSON names under its media keys. Media are copied (streamed) to the private disk. Re-importing the same
 * slug replaces the content, the page and every media.
 */
class OrganizerCalendarImportService
{
    public const CONTENT_FILE = 'calendar.json';

    public const PAGE_FILE = 'index.html';

    public const STORAGE_DIRECTORY = 'organizer-calendars';

    public const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerCalendarRepositoryInterface $calendarRepository,
        private readonly OrganizerCalendarMediaRepositoryInterface $mediaRepository,
        private readonly OrganizerCalendarMediaReferences $references,
        private readonly FilesystemManager $filesystemManager,
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws OrganizerCalendarImportException
     */
    public function import(ImportOrganizerCalendarDTO $data): ImportedOrganizerCalendarDTO
    {
        if (! OrganizerCalendarResolver::isValidSlug($data->slug)) {
            throw new OrganizerCalendarImportException(__('Invalid slug: use lowercase letters, digits and dashes (100 characters at most).'));
        }

        $directory = realpath($data->directory);
        if ($directory === false || ! is_dir($directory)) {
            throw new OrganizerCalendarImportException(__('Folder not found: :directory', ['directory' => $data->directory]));
        }

        $content = $this->readContent($directory);

        if ($this->organizerRepository->findFirst($data->organizerId) === null) {
            throw new OrganizerCalendarImportException(__('Organizer :id not found.', ['id' => $data->organizerId]));
        }

        /** @var OrganizerCalendarDomainObject|null $existing */
        $existing = $this->calendarRepository->findFirstWhere([Calendar::SLUG => $data->slug]);
        if ($existing !== null && $existing->getOrganizerId() !== $data->organizerId) {
            throw new OrganizerCalendarImportException(__('The slug :slug is already used by another organizer.', ['slug' => $data->slug]));
        }

        $phone = SmsPhoneNormalizer::normalize((string) ($data->phone ?? $existing?->getPhone() ?? ''));
        if ($phone === null) {
            throw new OrganizerCalendarImportException(__('A valid phone number is required to send the access code (--phone=+262...).'));
        }

        $title = trim((string) ($data->title ?? $existing?->getTitle() ?? $content['title'] ?? $content['titre'] ?? $data->slug));
        $frontHtml = is_file($directory.'/'.self::PAGE_FILE) ? (string) file_get_contents($directory.'/'.self::PAGE_FILE) : null;

        [$files, $missing] = $this->resolveFiles($directory, $this->references->collect($content));
        if ($missing !== [] && ! $data->allowMissingMedia) {
            throw new OrganizerCalendarImportException(__('Media files missing from the folder: :files', ['files' => implode(', ', $missing)]));
        }

        $diskName = (string) $this->config->get('filesystems.private', 'local');
        $disk = $this->filesystemManager->disk($diskName);
        $storedPaths = [];
        $oldMedia = [];

        try {
            $calendar = $this->databaseManager->transaction(function () use ($data, $existing, $phone, $title, $frontHtml, $content, $files, $diskName, $disk, &$storedPaths, &$oldMedia): OrganizerCalendarDomainObject {
                $calendar = $existing ?? $this->calendarRepository->create([
                    Calendar::ORGANIZER_ID => $data->organizerId,
                    Calendar::SLUG => $data->slug,
                    Calendar::TITLE => $title,
                    Calendar::PHONE => $phone,
                    Calendar::CONTENT => [],
                ]);

                $oldMedia = $this->mediaRepository->findWhere([Media::ORGANIZER_CALENDAR_ID => $calendar->getId()])->all();
                $batchDirectory = self::STORAGE_DIRECTORY.'/'.$calendar->getId().'/'.Str::lower(Str::random(16));
                $referenceByName = [];
                $index = 0;

                foreach ($files as $name => $absolutePath) {
                    $index++;
                    $storedName = $index.'-'.OrganizerCalendarMediaUrlGenerator::publicFileName($name);
                    $path = $disk->putFileAs($batchDirectory, new File($absolutePath), $storedName);
                    if ($path === false) {
                        throw new OrganizerCalendarImportException(__('Could not store the media :file.', ['file' => $name]));
                    }
                    $storedPaths[] = $path;

                    /** @var OrganizerCalendarMediaDomainObject $media */
                    $media = $this->mediaRepository->create([
                        Media::ORGANIZER_CALENDAR_ID => $calendar->getId(),
                        Media::FILE_NAME => $name,
                        Media::DISK => $diskName,
                        Media::PATH => $path,
                        Media::ORIGINAL_NAME => basename($name),
                        Media::MIME_TYPE => self::MIME_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))],
                        Media::SIZE => (int) filesize($absolutePath),
                    ]);
                    $referenceByName[$name] = OrganizerCalendarMediaReferences::reference($media->getId());
                }

                $rewritten = $this->references->map($content, function (string $value) use ($referenceByName): ?string {
                    if (self::isRemoteUrl($value)) {
                        return $value;
                    }

                    return $referenceByName[self::normalizeName($value)] ?? null;
                });

                if ($oldMedia !== []) {
                    $this->mediaRepository->deleteWhere([
                        [Media::ID, 'in', array_map(fn (OrganizerCalendarMediaDomainObject $media) => $media->getId(), $oldMedia)],
                    ]);
                }

                return $this->calendarRepository->updateFromArray($calendar->getId(), [
                    Calendar::TITLE => $title,
                    Calendar::PHONE => $phone,
                    Calendar::CONTENT => $rewritten,
                    Calendar::FRONT_HTML => $frontHtml,
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                $disk->delete($path);
            }

            throw $exception;
        }

        foreach ($oldMedia as $media) {
            $this->filesystemManager->disk($media->getDisk())->delete($media->getPath());
        }

        return new ImportedOrganizerCalendarDTO(
            calendarId: $calendar->getId(),
            created: $existing === null,
            title: $title,
            mediaCount: count($files),
            totalBytes: array_sum(array_map(fn (string $path) => (int) filesize($path), $files)),
            frontHtmlImported: $frontHtml !== null,
            missingFiles: $missing,
            unusedFiles: $this->unusedFiles($directory, array_keys($files)),
        );
    }

    public static function normalizeName(string $value): string
    {
        return (string) preg_replace('#^(\./)+#', '', trim(str_replace('\\', '/', $value)));
    }

    public static function isRemoteUrl(string $value): bool
    {
        return preg_match('#^https://#i', $value) === 1;
    }

    /**
     * @throws OrganizerCalendarImportException
     */
    private function readContent(string $directory): array
    {
        $file = $directory.'/'.self::CONTENT_FILE;
        if (! is_file($file)) {
            throw new OrganizerCalendarImportException(__('The folder has no :file.', ['file' => self::CONTENT_FILE]));
        }

        try {
            $content = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrganizerCalendarImportException(__(':file is not valid JSON: :error', ['file' => self::CONTENT_FILE, 'error' => $exception->getMessage()]));
        }

        if (! is_array($content)) {
            throw new OrganizerCalendarImportException(__(':file must contain a JSON object.', ['file' => self::CONTENT_FILE]));
        }

        return $content;
    }

    /**
     * @param  string[]  $references
     * @return array{0: array<string, string>, 1: string[]} files by referenced name, and the names with no usable file
     */
    private function resolveFiles(string $directory, array $references): array
    {
        $files = [];
        $missing = [];

        foreach ($references as $reference) {
            if (self::isRemoteUrl($reference)) {
                continue;
            }

            $name = self::normalizeName($reference);
            $absolutePath = $this->safePath($directory, $name);

            if ($absolutePath === null) {
                $missing[] = $reference;

                continue;
            }

            $files[$name] = $absolutePath;
        }

        return [$files, array_values(array_unique($missing))];
    }

    private function safePath(string $directory, string $name): ?string
    {
        if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! isset(self::MIME_TYPES[$extension])) {
            return null;
        }

        $candidate = $directory.'/'.$name;
        if (is_link($candidate) || ! is_file($candidate)) {
            return null;
        }

        $real = realpath($candidate);

        return $real !== false && str_starts_with($real, $directory.'/') ? $real : null;
    }

    /**
     * @param  string[]  $usedNames
     * @return string[]
     */
    private function unusedFiles(string $directory, array $usedNames): array
    {
        $unused = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $name = ltrim(substr($file->getPathname(), strlen($directory)), '/');
            if (str_starts_with(basename($name), '.') || in_array($name, [self::CONTENT_FILE, self::PAGE_FILE], true)) {
                continue;
            }
            if (! in_array($name, $usedNames, true)) {
                $unused[] = $name;
            }
        }

        sort($unused);

        return $unused;
    }
}
