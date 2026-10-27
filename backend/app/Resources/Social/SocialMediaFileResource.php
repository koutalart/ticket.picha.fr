<?php

declare(strict_types=1);

namespace HiEvents\Resources\Social;

use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\DomainObjects\Status\SocialMediaFileStatus;
use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use HiEvents\Services\Domain\Social\Media\SocialMediaUrlGenerator;

/**
 * @mixin SocialMediaFileDomainObject
 */
class SocialMediaFileResource extends BaseResource
{
    public function toArray($request): array
    {
        $isReady = $this->getStatus() === SocialMediaFileStatus::READY->name;

        return [
            'id' => $this->getId(),
            'status' => $this->getStatus(),
            'media_type' => $this->getMediaType(),
            'mime_type' => $this->getMimeType(),
            'original_name' => $this->getOriginalName(),
            'size_bytes' => $this->getSizeBytes(),
            'received_bytes' => $this->getReceivedBytes(),
            'chunk_size' => SocialMediaFileService::CHUNK_BYTES,
            'width' => $this->getWidth(),
            'height' => $this->getHeight(),
            'duration_ms' => $this->getDurationMs(),
            'video_codec' => $this->getVideoCodec(),
            'audio_codec' => $this->getAudioCodec(),
            'preview_url' => $isReady ? app(SocialMediaUrlGenerator::class)->previewUrl($this->getOrganizerId(), $this->getId()) : null,
        ];
    }
}
