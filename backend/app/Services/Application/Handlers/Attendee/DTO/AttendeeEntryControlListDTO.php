<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;

class AttendeeEntryControlListDTO extends BaseDataObject
{
    /**
     * @param  array<int, string>  $boxOfficePhonesByOrderId
     */
    public function __construct(
        public readonly Collection $attendees,
        public readonly Collection $orderQuestions,
        public readonly Collection $productQuestions,
        public readonly array $boxOfficePhonesByOrderId,
        public readonly string $timezone,
    ) {}
}
