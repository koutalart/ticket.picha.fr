<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class NewsletterSubscriberSearchDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $query = null,
        public readonly int     $page = 1,
        public readonly int     $perPage = 50,
    )
    {
    }
}
