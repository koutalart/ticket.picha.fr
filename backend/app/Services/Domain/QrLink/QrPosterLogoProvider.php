<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\QrPosterLogo;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\QrLink\DTO\QrPosterLogoDTO;
use Illuminate\Filesystem\FilesystemManager;

class QrPosterLogoProvider
{
    private const ICON_DIRECTORY = 'resources/qr-poster-icons';

    public function __construct(
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly FilesystemManager $filesystemManager,
    ) {}

    public function get(?string $logo, int $organizerId): ?QrPosterLogoDTO
    {
        $logo = QrPosterLogo::tryFrom((string) $logo);

        if ($logo === null) {
            return null;
        }

        if ($logo === QrPosterLogo::ORGANIZER) {
            return $this->organizerLogo($organizerId);
        }

        $path = base_path(self::ICON_DIRECTORY.'/'.$logo->iconFile());

        return is_readable($path) ? new QrPosterLogoDTO(blob: file_get_contents($path), isIcon: true) : null;
    }

    private function organizerLogo(int $organizerId): ?QrPosterLogoDTO
    {
        $image = $this->imageRepository->findFirstWhere([
            ImageDomainObjectAbstract::ENTITY_ID => $organizerId,
            ImageDomainObjectAbstract::ENTITY_TYPE => OrganizerDomainObject::class,
            ImageDomainObjectAbstract::TYPE => ImageType::ORGANIZER_LOGO->name,
        ]);

        if (! $image instanceof ImageDomainObject) {
            return null;
        }

        $blob = $this->filesystemManager->disk($image->getDisk())->get($image->getPath());

        return $blob ? new QrPosterLogoDTO(blob: $blob, isIcon: false) : null;
    }
}
