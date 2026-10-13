<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Meta\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class TestMetaConversionsApiResultDTO extends BaseDataObject
{
    public function __construct(
        public string $pixel_id,
        public ?string $pixel_name,
        public bool $test_event_sent,
    ) {}
}
