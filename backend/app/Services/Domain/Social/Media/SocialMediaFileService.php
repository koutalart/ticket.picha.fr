<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Media;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Generated\SocialMediaFileDomainObjectAbstract;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\DomainObjects\Status\SocialMediaFileStatus;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Repository\Interfaces\SocialMediaFileRepositoryInterface;
use HiEvents\Services\Domain\Social\Media\DTO\SocialMediaMetadataDTO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports photos and videos in chunks (under the 20 MB request limit of the server), checks them and keeps the original file.
 */
class SocialMediaFileService
{
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    public const ORPHAN_RETENTION_DAYS = 30;

    public const PUBLISHED_RETENTION_DAYS = 30;

    public const STALE_UPLOAD_HOURS = 24;

    private const TEMP_DISK = 'local';

    private const TEMP_DIRECTORY = 'social-uploads';

    private const FINAL_DIRECTORY = 'social-media';

    public function __construct(
        private readonly SocialMediaFileRepositoryInterface $fileRepository,
        private readonly SocialMediaRules $rules,
        private readonly SocialMediaProbe $probe,
        private readonly FilesystemManager $filesystemManager,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws InvalidSocialMediaFileException
     */
    public function start(int $accountId, int $organizerId, ?int $userId, string $originalName, string $mimeType, int $sizeBytes): SocialMediaFileDomainObject
    {
        $type = $this->rules->typeForMime($mimeType);
        $this->rules->assertSize($type, $sizeBytes);

        return $this->fileRepository->create([
            SocialMediaFileDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SocialMediaFileDomainObjectAbstract::ACCOUNT_ID => $accountId,
            SocialMediaFileDomainObjectAbstract::STATUS => SocialMediaFileStatus::UPLOADING->name,
            SocialMediaFileDomainObjectAbstract::DISK => (string) $this->config->get('filesystems.private', 'local'),
            SocialMediaFileDomainObjectAbstract::ORIGINAL_NAME => Str::limit(basename($originalName), 250, ''),
            SocialMediaFileDomainObjectAbstract::MIME_TYPE => strtolower($mimeType),
            SocialMediaFileDomainObjectAbstract::MEDIA_TYPE => $type->name,
            SocialMediaFileDomainObjectAbstract::SIZE_BYTES => $sizeBytes,
            SocialMediaFileDomainObjectAbstract::RECEIVED_BYTES => 0,
            SocialMediaFileDomainObjectAbstract::UPLOADED_BY_USER_ID => $userId,
            SocialMediaFileDomainObjectAbstract::PURGE_AFTER => Carbon::now()->addHours(self::STALE_UPLOAD_HOURS),
        ]);
    }

    /**
     * Chunks arrive in order; each one but the last is CHUNK_BYTES long.
     *
     * @throws InvalidSocialMediaFileException
     * @throws SocialMediaFileNotFoundException
     */
    public function appendChunk(int $organizerId, int $fileId, int $index, UploadedFile $chunk): SocialMediaFileDomainObject
    {
        $file = $this->findForOrganizer($organizerId, $fileId);

        if ($file->getStatus() !== SocialMediaFileStatus::UPLOADING->name) {
            throw new InvalidSocialMediaFileException(__('This file has already been imported.'));
        }

        if ($index !== intdiv($file->getReceivedBytes(), self::CHUNK_BYTES) || $file->getReceivedBytes() % self::CHUNK_BYTES !== 0) {
            throw new InvalidSocialMediaFileException(__('The import was interrupted. Please import the file again.'));
        }

        $chunkSize = (int) $chunk->getSize();
        $received = $file->getReceivedBytes() + $chunkSize;

        if ($chunkSize <= 0 || $chunkSize > self::CHUNK_BYTES || $received > $file->getSizeBytes()) {
            throw new InvalidSocialMediaFileException(__('The import was interrupted. Please import the file again.'));
        }

        $tempPath = $this->tempPath($file);
        $disk = $this->filesystemManager->disk(self::TEMP_DISK);
        $disk->makeDirectory(self::TEMP_DIRECTORY);

        $source = fopen($chunk->getRealPath(), 'rb');
        $target = fopen($disk->path($tempPath), $index === 0 ? 'wb' : 'ab');

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        return $this->fileRepository->updateFromArray($file->getId(), [
            SocialMediaFileDomainObjectAbstract::RECEIVED_BYTES => $received,
        ]);
    }

    /**
     * Checks the real type of the file, its size and, for a reel or a story, its ratio and duration, then stores the original.
     *
     * @throws InvalidSocialMediaFileException
     * @throws SocialMediaFileNotFoundException
     */
    public function complete(int $organizerId, int $fileId, ?SocialPostFormat $format): SocialMediaFileDomainObject
    {
        $file = $this->findForOrganizer($organizerId, $fileId);

        if ($file->getStatus() === SocialMediaFileStatus::READY->name) {
            return $file;
        }

        $tempDisk = $this->filesystemManager->disk(self::TEMP_DISK);
        $tempPath = $this->tempPath($file);

        if ($file->getReceivedBytes() !== $file->getSizeBytes() || ! $tempDisk->exists($tempPath)) {
            throw new InvalidSocialMediaFileException(__('The import was interrupted. Please import the file again.'));
        }

        $localPath = $tempDisk->path($tempPath);

        try {
            $mimeType = $this->detectMimeType($localPath);
            $type = $this->rules->typeForMime($mimeType);
            $this->rules->assertSize($type, (int) filesize($localPath));
            $metadata = $this->probe->probe($localPath, $type);

            if ($format !== null) {
                $this->rules->assertFitsFormat($format, $type, $metadata);
            }
        } catch (InvalidSocialMediaFileException $exception) {
            $this->discard($file);

            throw $exception;
        }

        $path = sprintf('%s/%d/%s.%s', self::FINAL_DIRECTORY, $organizerId, Str::uuid(), SocialMediaRules::EXTENSIONS[$mimeType]);
        $stream = fopen($localPath, 'rb');

        try {
            $this->filesystemManager->disk($file->getDisk())->writeStream($path, $stream);
        } catch (Throwable $exception) {
            $this->logger->error('Social media file could not be stored', [
                'file_id' => $file->getId(),
                'disk' => $file->getDisk(),
                'message' => $exception->getMessage(),
            ]);
            $this->discard($file);

            throw new InvalidSocialMediaFileException(__('The file could not be stored. Please try again.'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $tempDisk->delete($tempPath);

        return $this->fileRepository->updateFromArray($file->getId(), [
            SocialMediaFileDomainObjectAbstract::STATUS => SocialMediaFileStatus::READY->name,
            SocialMediaFileDomainObjectAbstract::PATH => $path,
            SocialMediaFileDomainObjectAbstract::MIME_TYPE => $mimeType,
            SocialMediaFileDomainObjectAbstract::MEDIA_TYPE => $type->name,
            SocialMediaFileDomainObjectAbstract::WIDTH => $metadata->width,
            SocialMediaFileDomainObjectAbstract::HEIGHT => $metadata->height,
            SocialMediaFileDomainObjectAbstract::DURATION_MS => $metadata->durationMs,
            SocialMediaFileDomainObjectAbstract::VIDEO_CODEC => $metadata->videoCodec,
            SocialMediaFileDomainObjectAbstract::AUDIO_CODEC => $metadata->audioCodec,
            SocialMediaFileDomainObjectAbstract::PURGE_AFTER => Carbon::now()->addDays(self::ORPHAN_RETENTION_DAYS),
        ]);
    }

    /**
     * @throws SocialMediaFileNotFoundException
     */
    public function findReady(int $organizerId, int $fileId): SocialMediaFileDomainObject
    {
        $file = $this->findForOrganizer($organizerId, $fileId);

        if ($file->getStatus() !== SocialMediaFileStatus::READY->name) {
            throw new SocialMediaFileNotFoundException(__('Media not found.'));
        }

        return $file;
    }

    /**
     * @param  int[]  $fileIds
     */
    public function keep(array $fileIds): void
    {
        $this->fileRepository->setPurgeAfter($fileIds, null);
    }

    /**
     * @param  int[]  $fileIds
     */
    public function purgeAfterDays(array $fileIds, int $days): void
    {
        $this->fileRepository->setPurgeAfter($fileIds, Carbon::now()->addDays($days));
    }

    public function purgeDue(int $limit = 200): int
    {
        $files = $this->fileRepository->findDueForPurge(Carbon::now(), $limit);

        foreach ($files as $file) {
            $this->discard($file);
        }

        return $files->count();
    }

    public function metadata(SocialMediaFileDomainObject $file): SocialMediaMetadataDTO
    {
        return new SocialMediaMetadataDTO(
            width: $file->getWidth(),
            height: $file->getHeight(),
            durationMs: $file->getDurationMs(),
            videoCodec: $file->getVideoCodec(),
            audioCodec: $file->getAudioCodec(),
        );
    }

    private function discard(SocialMediaFileDomainObject $file): void
    {
        try {
            $this->filesystemManager->disk(self::TEMP_DISK)->delete($this->tempPath($file));

            if ($file->getPath() !== null) {
                $this->filesystemManager->disk($file->getDisk())->delete($file->getPath());
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Social media file could not be deleted', [
                'file_id' => $file->getId(),
                'message' => $exception->getMessage(),
            ]);
        }

        $this->fileRepository->deleteById($file->getId());
    }

    /**
     * @throws SocialMediaFileNotFoundException
     */
    private function findForOrganizer(int $organizerId, int $fileId): SocialMediaFileDomainObject
    {
        /** @var SocialMediaFileDomainObject|null $file */
        $file = $this->fileRepository->findFirstWhere([
            SocialMediaFileDomainObjectAbstract::ID => $fileId,
            SocialMediaFileDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($file === null) {
            throw new SocialMediaFileNotFoundException(__('Media not found.'));
        }

        return $file;
    }

    private function detectMimeType(string $path): string
    {
        $mimeType = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));

        return $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;
    }

    private function tempPath(SocialMediaFileDomainObject $file): string
    {
        return self::TEMP_DIRECTORY.'/'.$file->getId().'.part';
    }
}
