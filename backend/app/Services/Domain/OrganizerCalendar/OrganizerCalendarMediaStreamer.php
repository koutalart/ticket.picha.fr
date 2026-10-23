<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\OrganizerCalendarMediaDomainObject;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a calendar media inline. On a local disk it answers HTTP Range requests (206), which iOS Safari needs to play videos.
 */
class OrganizerCalendarMediaStreamer
{
    public function __construct(
        private readonly FilesystemManager $filesystemManager,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     */
    public function response(OrganizerCalendarMediaDomainObject $media, CarbonInterface $expiresAt): Response
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->filesystemManager->disk($media->getDisk());

        if (! $disk->exists($media->getPath())) {
            throw new OrganizerCalendarNotFoundException('Calendar media file missing');
        }

        $maxAge = max(0, $expiresAt->getTimestamp() - time());
        $headers = [
            'Content-Type' => $media->getMimeType(),
            'Cache-Control' => 'private, max-age='.$maxAge,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        $fileName = OrganizerCalendarMediaUrlGenerator::publicFileName($media->getFileName());

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $response = new BinaryFileResponse($disk->path($media->getPath()), Response::HTTP_OK, $headers, false, null, false, true);
            $response->headers->set('Content-Type', $media->getMimeType());
            $response->setContentDisposition(HeaderUtils::DISPOSITION_INLINE, $fileName, 'media');

            return $response;
        }

        return $disk->response($media->getPath(), $fileName, $headers, 'inline');
    }
}
