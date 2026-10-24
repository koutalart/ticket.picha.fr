<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SocialMediaFileStatus: string
{
    use BaseEnum;

    case UPLOADING = 'UPLOADING';
    case READY = 'READY';
}
