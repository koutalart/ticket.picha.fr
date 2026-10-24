<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Media;

use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an imported media as stored, never re-encoded. On a local disk it answers HTTP Range requests (206).
 */
class SocialMediaStreamer
{
    public function __construct(
        private readonly FilesystemManager $filesystemManager,
    ) {}

    /**
     * @throws SocialMediaFileNotFoundException
     */
    public function response(SocialMediaFileDomainObject $file, bool $private): Response
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->filesystemManager->disk($file->getDisk());

        if ($file->getPath() === null || ! $disk->exists($file->getPath())) {
            throw new SocialMediaFileNotFoundException(__('Media not found.'));
        }

        $headers = [
            'Content-Type' => $file->getMimeType(),
            'Cache-Control' => $private ? 'private, max-age=3600' : 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        $fileName = SocialMediaUrlGenerator::publicFileName($file);

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $response = new BinaryFileResponse($disk->path($file->getPath()), Response::HTTP_OK, $headers, false, null, false, true);
            $response->headers->set('Content-Type', $file->getMimeType());
            $response->setContentDisposition(HeaderUtils::DISPOSITION_INLINE, $fileName, 'media');

            return $response;
        }

        return $disk->response($file->getPath(), $fileName, $headers, 'inline');
    }
}
