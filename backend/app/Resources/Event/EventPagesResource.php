<?php

namespace HiEvents\Resources\Event;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\Event\DTO\EventArtistDTO;
use HiEvents\Services\Domain\Event\DTO\EventFaqItemDTO;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use HiEvents\Services\Domain\Event\DTO\EventPageSectionDTO;

/**
 * @mixin EventPagesDTO
 */
class EventPagesResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'available_pages' => $this->available_pages,
            'artists' => $this->artists->map(fn (EventArtistDTO $artist) => [
                'name' => $artist->name,
                'slug' => $artist->slug,
                'kind' => $artist->kind,
                'image_id' => $artist->image_id,
                'photo_url' => $artist->photo_url,
                'bio' => $artist->bio,
                'song' => $artist->song,
                'links' => (object) ($artist->links ?? []),
            ])->values()->all(),
            'practical_info' => $this->practical_info->map(fn (EventPageSectionDTO $section) => [
                'title' => $section->title,
                'content' => $section->content,
            ])->values()->all(),
            'faq' => $this->faq->map(fn (EventFaqItemDTO $item) => [
                'question' => $item->question,
                'answer' => $item->answer,
            ])->values()->all(),
        ];
    }
}
