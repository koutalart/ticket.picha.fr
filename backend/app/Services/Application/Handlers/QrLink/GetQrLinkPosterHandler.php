<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Enums\QrPosterTemplate;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use HiEvents\Services\Domain\QrLink\QrPosterLogoProvider;
use HiEvents\Services\Domain\QrLink\QrPosterRenderer;
use Illuminate\Filesystem\FilesystemManager;

class GetQrLinkPosterHandler
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly QrLinkUrlService $urlService,
        private readonly QrPosterRenderer $renderer,
        private readonly QrPosterLogoProvider $logoProvider,
        private readonly FilesystemManager $filesystemManager,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $qrLinkId): string
    {
        /** @var QrLinkDomainObject|null $qrLink */
        $qrLink = $this->qrLinkRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findFirstWhere([
                QrLinkDomainObjectAbstract::ID => $qrLinkId,
                QrLinkDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ]);

        if ($qrLink === null) {
            throw new ResourceNotFoundException(__('QR code not found'));
        }

        $design = $qrLink->getDesign() ?? [];
        $title = $qrLink->getTitle()
            ?? $qrLink->getEvent()?->getTitle()
            ?? $this->organizerRepository->findById($organizerId)->getName();

        return $this->renderer->render(
            url: $this->urlService->getPublicUrl($qrLink),
            design: $design,
            title: $title,
            backgroundBlob: $this->backgroundBlob($design, $qrLink->getAccountId()),
            logo: $this->logoProvider->get($design['poster_logo'] ?? null, $organizerId),
        );
    }

    private function backgroundBlob(array $design, int $accountId): ?string
    {
        if (($design['template'] ?? null) !== QrPosterTemplate::CUSTOM->value || empty($design['background_image_id'])) {
            return null;
        }

        $image = $this->imageRepository->findFirstWhere([
            ImageDomainObjectAbstract::ID => (int) $design['background_image_id'],
            ImageDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        if (! $image instanceof ImageDomainObject) {
            return null;
        }

        return $this->filesystemManager->disk($image->getDisk())->get($image->getPath());
    }
}
