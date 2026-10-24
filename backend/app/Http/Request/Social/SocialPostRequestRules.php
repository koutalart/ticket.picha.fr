<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use Illuminate\Validation\Rule;

trait SocialPostRequestRules
{
    protected function contentRules(): array
    {
        return [
            'caption' => ['nullable', 'string', 'max:63206'],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*.url' => ['required', 'string', 'url', 'max:2048'],
            'media.*.type' => ['required', Rule::in(SocialMediaType::valuesArray())],
            'link' => ['nullable', 'string', 'url', 'max:2048'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return SocialMediaItemDTO[]|null
     */
    public function mediaItems(): ?array
    {
        $media = $this->validated('media');

        if ($media === null) {
            return null;
        }

        return array_map(
            fn (array $item) => new SocialMediaItemDTO(url: trim((string) $item['url']), type: SocialMediaType::fromName((string) $item['type'])),
            array_values($media),
        );
    }
}
