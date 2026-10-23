<?php

namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\EventArtistKind;
use HiEvents\DomainObjects\Enums\EventPageType;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Services\Domain\Event\DTO\EventArtistDTO;
use HiEvents\Services\Domain\Event\DTO\EventFaqItemDTO;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use HiEvents\Services\Domain\Event\DTO\EventPageSectionDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EventPagesService
{
    public const LINK_TYPES = ['instagram', 'facebook', 'tiktok', 'youtube', 'spotify', 'website'];

    private const DEFAULT_ARTIST_SLUG = 'artiste';

    public function __construct(
        private readonly HtmlPurifierService $htmlPurifier,
    ) {}

    /**
     * Sanitizes the pages content and returns what should be stored, or null when every page is empty.
     *
     * @param  int[]  $allowedImageIds
     */
    public function normalize(EventPagesDTO $pages, array $allowedImageIds): ?array
    {
        $usedSlugs = [];

        $artists = $pages->artists
            ->map(function (EventArtistDTO $artist) use (&$usedSlugs, $allowedImageIds): ?array {
                $name = $this->plainText($artist->name);
                if ($name === null) {
                    return null;
                }

                return [
                    'name' => $name,
                    'slug' => $this->uniqueSlug($name, $usedSlugs),
                    'kind' => $artist->kind === EventArtistKind::GROUP->name
                        ? EventArtistKind::GROUP->name
                        : EventArtistKind::PERSON->name,
                    'image_id' => in_array($artist->image_id, $allowedImageIds, true) ? $artist->image_id : null,
                    'bio' => $this->richText($artist->bio),
                    'song' => $this->plainText($artist->song),
                    'links' => $this->links($artist->links ?? []),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $practicalInfo = $pages->practical_info
            ->map(function (EventPageSectionDTO $section): ?array {
                $title = $this->plainText($section->title);
                $content = $this->richText($section->content);

                return $title === null ? null : ['title' => $title, 'content' => $content];
            })
            ->filter()
            ->values()
            ->all();

        $faq = $pages->faq
            ->map(function (EventFaqItemDTO $item): ?array {
                $question = $this->plainText($item->question);
                $answer = $this->richText($item->answer);

                return $question === null || $answer === null ? null : ['question' => $question, 'answer' => $answer];
            })
            ->filter()
            ->values()
            ->all();

        if ($artists === [] && $practicalInfo === [] && $faq === []) {
            return null;
        }

        return [
            EventPageType::ARTISTS->contentKey() => $artists,
            EventPageType::PRACTICAL_INFO->contentKey() => $practicalInfo,
            EventPageType::FAQ->contentKey() => $faq,
        ];
    }

    /**
     * @param  Collection<int, ImageDomainObject>  $images
     */
    public function present(array|string|null $stored, Collection $images): EventPagesDTO
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        $stored = is_array($stored) ? $stored : [];

        $photoUrls = $images
            ->filter(fn (ImageDomainObject $image) => $image->getPath() !== null)
            ->mapWithKeys(fn (ImageDomainObject $image) => [$image->getId() => Url::getCdnUrl($image->getPath())]);

        $artists = collect($stored[EventPageType::ARTISTS->contentKey()] ?? [])
            ->map(fn (array $artist) => new EventArtistDTO(
                name: (string) ($artist['name'] ?? ''),
                slug: $artist['slug'] ?? null,
                kind: $artist['kind'] ?? EventArtistKind::PERSON->name,
                image_id: isset($artist['image_id']) ? (int) $artist['image_id'] : null,
                photo_url: isset($artist['image_id']) ? $photoUrls->get((int) $artist['image_id']) : null,
                bio: $artist['bio'] ?? null,
                song: $artist['song'] ?? null,
                links: $artist['links'] ?? [],
            ))
            ->values();

        $practicalInfo = collect($stored[EventPageType::PRACTICAL_INFO->contentKey()] ?? [])
            ->map(fn (array $section) => new EventPageSectionDTO(
                title: (string) ($section['title'] ?? ''),
                content: $section['content'] ?? null,
            ))
            ->values();

        $faq = collect($stored[EventPageType::FAQ->contentKey()] ?? [])
            ->map(fn (array $item) => new EventFaqItemDTO(
                question: (string) ($item['question'] ?? ''),
                answer: (string) ($item['answer'] ?? ''),
            ))
            ->values();

        $availablePages = [];
        foreach (EventPageType::cases() as $pageType) {
            if (! empty($stored[$pageType->contentKey()])) {
                $availablePages[] = $pageType->value;
            }
        }

        return new EventPagesDTO(
            artists: $artists,
            practical_info: $practicalInfo,
            faq: $faq,
            available_pages: $availablePages,
        );
    }

    private function uniqueSlug(string $name, array &$usedSlugs): string
    {
        $base = Str::slug($name) ?: self::DEFAULT_ARTIST_SLUG;
        $slug = $base;
        $suffix = 2;

        while (in_array($slug, $usedSlugs, true)) {
            $slug = $base.'-'.$suffix++;
        }

        $usedSlugs[] = $slug;

        return $slug;
    }

    private function plainText(?string $value): ?string
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : $value;
    }

    private function richText(?string $html): ?string
    {
        $purified = $this->htmlPurifier->purify($html);

        if ($purified === null || trim(strip_tags($purified)) === '') {
            return null;
        }

        return trim($purified);
    }

    private function links(array $links): array
    {
        $clean = [];

        foreach (self::LINK_TYPES as $type) {
            $url = trim((string) ($links[$type] ?? ''));

            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
                $clean[$type] = $url;
            }
        }

        return $clean;
    }
}
