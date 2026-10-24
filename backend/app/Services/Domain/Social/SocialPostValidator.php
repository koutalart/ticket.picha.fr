<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social;

use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\DTO\SocialPostDataDTO;

class SocialPostValidator
{
    public const INSTAGRAM_CAPTION_MAX_LENGTH = 2200;

    public const CAROUSEL_MIN_ITEMS = 2;

    public const CAROUSEL_MAX_ITEMS = 10;

    /**
     * @throws InvalidSocialPostException
     */
    public function validate(SocialPostDataDTO $post, ?OrganizerSocialConnectionDomainObject $connection): void
    {
        if ($connection === null) {
            throw new InvalidSocialPostException(__('Connect a Facebook page before scheduling a post.'));
        }

        if ($post->network === SocialNetwork::INSTAGRAM && ! $connection->getInstagramAccountId()) {
            throw new InvalidSocialPostException(__('No Instagram professional account is linked to the connected Facebook page.'));
        }

        foreach ($post->media as $item) {
            if (! filter_var($item->url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($item->url), 'https://')) {
                throw new InvalidSocialPostException(__('Each media must be a public https:// link.'));
            }
        }

        if ($post->network === SocialNetwork::INSTAGRAM && mb_strlen((string) $post->caption) > self::INSTAGRAM_CAPTION_MAX_LENGTH) {
            throw new InvalidSocialPostException(__('Instagram captions are limited to :max characters.', ['max' => self::INSTAGRAM_CAPTION_MAX_LENGTH]));
        }

        $images = array_filter($post->media, fn (SocialMediaItemDTO $item) => ! $item->isVideo());
        $videos = array_filter($post->media, fn (SocialMediaItemDTO $item) => $item->isVideo());

        match ($post->format) {
            SocialPostFormat::TEXT => $this->validateText($post),
            SocialPostFormat::IMAGE => $this->expect(count($post->media) === 1 && count($images) === 1, __('An image post needs exactly one image.')),
            SocialPostFormat::CAROUSEL => $this->expect(
                count($videos) === 0 && count($images) >= self::CAROUSEL_MIN_ITEMS && count($images) <= self::CAROUSEL_MAX_ITEMS,
                __('A carousel needs between :min and :max images.', ['min' => self::CAROUSEL_MIN_ITEMS, 'max' => self::CAROUSEL_MAX_ITEMS]),
            ),
            SocialPostFormat::REEL => $this->expect(count($post->media) === 1 && count($videos) === 1, __('A reel needs exactly one video.')),
            SocialPostFormat::STORY => $this->expect(count($post->media) === 1, __('A story needs exactly one image or video.')),
        };

        if ($post->network === SocialNetwork::INSTAGRAM) {
            foreach ($images as $image) {
                $this->expect($this->looksLikeJpeg($image->url), __('Instagram only accepts JPEG images (.jpg).'));
            }
        }
    }

    /**
     * @throws InvalidSocialPostException
     */
    private function validateText(SocialPostDataDTO $post): void
    {
        $this->expect($post->network === SocialNetwork::FACEBOOK, __('Instagram does not accept text-only posts.'));
        $this->expect(trim((string) $post->caption) !== '' || ! empty($post->link), __('A text post needs a message or a link.'));
        $this->expect($post->media === [], __('A text post cannot contain media.'));
    }

    private function looksLikeJpeg(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return ! preg_match('/\.(png|gif|webp|avif|heic|bmp|tiff?)$/', $path);
    }

    /**
     * @throws InvalidSocialPostException
     */
    private function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidSocialPostException($message);
        }
    }
}
