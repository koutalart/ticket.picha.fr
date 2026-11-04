<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RequestSocialPostChangesDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $postId,
        public readonly ?int $userId,
        public readonly string $comment,
    ) {}
}
