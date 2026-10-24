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
            'media.*.url' => ['required_without:media.*.file_id', 'nullable', 'string', 'url', 'max:2048'],
            'media.*.file_id' => ['required_without:media.*.url', 'nullable', 'integer'],
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
            fn (array $item) => new SocialMediaItemDTO(
                url: trim((string) ($item['url'] ?? '')),
                type: SocialMediaType::fromName((string) $item['type']),
                fileId: isset($item['file_id']) ? (int) $item['file_id'] : null,
            ),
            array_values($media),
        );
    }
}
