<?php

namespace HiEvents\Services\Domain\Organizer\Font;

use HiEvents\DomainObjects\Enums\OrganizerFontFormat;
use HiEvents\DomainObjects\Enums\OrganizerFontWeight;
use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Exceptions\InvalidOrganizerFontException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerFontRepositoryInterface;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class OrganizerFontService
{
    public const MAX_FILE_SIZE_KB = 2048;

    private const STORAGE_DIRECTORY = 'organizer_fonts';

    public function __construct(
        private readonly OrganizerFontRepositoryInterface $organizerFontRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly FilesystemManager $filesystemManager,
        private readonly Repository $config,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws InvalidOrganizerFontException
     */
    public function detectFormat(UploadedFile $file): OrganizerFontFormat
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        $firstBytes = $handle ? (string) fread($handle, 12) : '';
        if ($handle) {
            fclose($handle);
        }

        $format = strlen($firstBytes) >= 12 ? OrganizerFontFormat::fromSignature($firstBytes) : null;

        if ($format === null) {
            throw new InvalidOrganizerFontException(__('This file is not a valid font. Accepted formats: WOFF2, WOFF, TTF, OTF.'));
        }

        if (! $format->matchesExtension($file->getClientOriginalExtension())) {
            throw new InvalidOrganizerFontException(__('The file extension does not match its content.'));
        }

        return $format;
    }

    /**
     * @throws InvalidOrganizerFontException
     */
    public function upload(
        int $organizerId,
        int $accountId,
        string $familyName,
        OrganizerFontWeight $weight,
        UploadedFile $file,
    ): OrganizerFontDomainObject {
        $format = $this->detectFormat($file);
        $disk = $this->config->get('filesystems.public');

        $filename = Str::slug($familyName).'-'.$weight->value.'-'.Str::random(8).'.'.$format->fileExtension();

        $path = $this->filesystemManager->disk($disk)->putFileAs(
            path: self::STORAGE_DIRECTORY.'/'.$organizerId,
            file: $file,
            name: $filename,
            options: [
                'visibility' => 'public',
                'ContentType' => $format->mimeType(),
            ],
        );

        if ($path === false) {
            $this->logger->error('Could not store organizer font', [
                'organizer_id' => $organizerId,
                'disk' => $disk,
            ]);

            throw new InvalidOrganizerFontException(__('The font could not be saved. Please try again.'));
        }

        /** @var OrganizerFontDomainObject|null $previous */
        $previous = $this->organizerFontRepository->findFirstWhere([
            'organizer_id' => $organizerId,
            'weight' => $weight->value,
        ]);

        $font = $this->databaseManager->transaction(function () use ($organizerId, $accountId, $familyName, $weight, $format, $file, $disk, $path, $previous) {
            if ($previous !== null) {
                $this->organizerFontRepository->deleteById($previous->getId());
            }

            $this->organizerFontRepository->updateWhere(
                ['family_name' => $familyName],
                ['organizer_id' => $organizerId],
            );

            return $this->organizerFontRepository->create([
                'account_id' => $accountId,
                'organizer_id' => $organizerId,
                'family_name' => $familyName,
                'weight' => $weight->value,
                'format' => $format->value,
                'filename' => $file->getClientOriginalName(),
                'disk' => $disk,
                'path' => $path,
                'size' => (int) $file->getSize(),
            ]);
        });

        if ($previous !== null) {
            $this->deleteStoredFile($previous);
        }

        return $font;
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function delete(int $organizerId, int $fontId): void
    {
        /** @var OrganizerFontDomainObject|null $font */
        $font = $this->organizerFontRepository->findFirstWhere([
            'id' => $fontId,
            'organizer_id' => $organizerId,
        ]);

        if ($font === null) {
            throw new ResourceNotFoundException(__('Font not found'));
        }

        $this->organizerFontRepository->deleteById($font->getId());
        $this->deleteStoredFile($font);
    }

    public function renameFamily(int $organizerId, string $familyName): Collection
    {
        $this->organizerFontRepository->updateWhere(
            ['family_name' => $familyName],
            ['organizer_id' => $organizerId],
        );

        return $this->getFonts($organizerId);
    }

    /**
     * @return Collection<OrganizerFontDomainObject>
     */
    public function getFonts(int $organizerId): Collection
    {
        return $this->organizerFontRepository
            ->findWhere(['organizer_id' => $organizerId])
            ->sortBy(fn (OrganizerFontDomainObject $font) => $font->getWeight())
            ->values();
    }

    public function isAvailableForOrganizer(int $organizerId): bool
    {
        return self::hasRegularWeight($this->getFonts($organizerId));
    }

    public function isAvailableForEvent(int $eventId): bool
    {
        $event = $this->eventRepository->findFirst($eventId);

        return $event !== null && $this->isAvailableForOrganizer($event->getOrganizerId());
    }

    /**
     * @param  Collection<OrganizerFontDomainObject>|null  $fonts
     */
    public static function hasRegularWeight(?Collection $fonts): bool
    {
        return $fonts !== null && $fonts->contains(
            fn (OrganizerFontDomainObject $font) => $font->getWeight() === OrganizerFontWeight::Regular->value
        );
    }

    private function deleteStoredFile(OrganizerFontDomainObject $font): void
    {
        try {
            $this->filesystemManager->disk($font->getDisk())->delete($font->getPath());
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not delete organizer font file', [
                'path' => $font->getPath(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
