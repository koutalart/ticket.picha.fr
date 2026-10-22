<?php

namespace HiEvents\Services\Domain\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class EventPagesDTO extends BaseDataObject
{
    public function __construct(
        #[DataCollectionOf(EventArtistDTO::class)]
        public readonly Collection $artists,
        #[DataCollectionOf(EventPageSectionDTO::class)]
        public readonly Collection $practical_info,
        #[DataCollectionOf(EventFaqItemDTO::class)]
        public readonly Collection $faq,
        public readonly array $available_pages = [],
    ) {}
}
