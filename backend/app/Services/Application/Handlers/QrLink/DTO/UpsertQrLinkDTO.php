<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Status\QrLinkStatus;

class UpsertQrLinkDTO extends BaseDataObject
{
    public function __construct(
        public int $accountId,
        public int $organizerId,
        public string $name,
        public QrLinkDestinationType $destinationType,
        public QrLinkStatus $status,
        public ?int $eventId = null,
        public ?string $destinationUrl = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?array $socialLinks = null,
        public ?array $design = null,
    ) {}
}
