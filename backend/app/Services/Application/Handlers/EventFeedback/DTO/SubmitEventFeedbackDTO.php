<?php

namespace HiEvents\Services\Application\Handlers\EventFeedback\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SubmitEventFeedbackDTO extends BaseDataObject
{
    public function __construct(
        public int $event_id,
        public int $rating,
        public ?string $contact_code = null,
        public ?string $title = null,
        public ?string $first_name = null,
        public ?string $artists = null,
        public ?string $welcome = null,
        public ?string $come_back = null,
        public ?string $message = null,
        public bool $publish_consent = false,
        public ?string $ip = null,
    ) {}
}
