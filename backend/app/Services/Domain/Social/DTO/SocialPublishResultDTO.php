<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Status\SocialPostStatus;

class SocialPublishResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly SocialPostStatus $status,
        public readonly ?string $error = null,
    ) {}
}
