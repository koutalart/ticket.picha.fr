<?php

namespace Tests\Unit\Services\Domain\Social;

use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\DTO\SocialPostDataDTO;
use HiEvents\Services\Domain\Social\SocialPostValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SocialPostValidatorTest extends TestCase
{
    public static function validPosts(): array
    {
        return [
            'facebook text' => [SocialNetwork::FACEBOOK, SocialPostFormat::TEXT, [], 'https://ticket.picha.fr'],
            'instagram image' => [SocialNetwork::INSTAGRAM, SocialPostFormat::IMAGE, [['https://cdn.test/a.jpg', 'IMAGE']]],
            'facebook png image' => [SocialNetwork::FACEBOOK, SocialPostFormat::IMAGE, [['https://cdn.test/a.png', 'IMAGE']]],
            'instagram carousel' => [SocialNetwork::INSTAGRAM, SocialPostFormat::CAROUSEL, [['https://cdn.test/1.jpg', 'IMAGE'], ['https://cdn.test/2.jpeg', 'IMAGE']]],
            'instagram reel' => [SocialNetwork::INSTAGRAM, SocialPostFormat::REEL, [['https://cdn.test/r.mp4', 'VIDEO']]],
            'facebook video story' => [SocialNetwork::FACEBOOK, SocialPostFormat::STORY, [['https://cdn.test/s.mp4', 'VIDEO']]],
        ];
    }

    public static function invalidPosts(): array
    {
        return [
            'instagram text' => [SocialNetwork::INSTAGRAM, SocialPostFormat::TEXT, []],
            'instagram png' => [SocialNetwork::INSTAGRAM, SocialPostFormat::IMAGE, [['https://cdn.test/a.png', 'IMAGE']]],
            'http media' => [SocialNetwork::FACEBOOK, SocialPostFormat::IMAGE, [['http://cdn.test/a.jpg', 'IMAGE']]],
            'one image carousel' => [SocialNetwork::FACEBOOK, SocialPostFormat::CAROUSEL, [['https://cdn.test/1.jpg', 'IMAGE']]],
            'reel with an image' => [SocialNetwork::INSTAGRAM, SocialPostFormat::REEL, [['https://cdn.test/r.jpg', 'IMAGE']]],
            'story with two media' => [SocialNetwork::INSTAGRAM, SocialPostFormat::STORY, [['https://cdn.test/1.jpg', 'IMAGE'], ['https://cdn.test/2.jpg', 'IMAGE']]],
            'empty text' => [SocialNetwork::FACEBOOK, SocialPostFormat::TEXT, [], null, ''],
        ];
    }

    #[DataProvider('validPosts')]
    public function test_valid_posts_pass(SocialNetwork $network, SocialPostFormat $format, array $media, ?string $link = null): void
    {
        (new SocialPostValidator)->validate($this->postIn($network, $format, $media, $link, 'Halloween'), $this->connection());

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidPosts')]
    public function test_invalid_posts_are_refused(SocialNetwork $network, SocialPostFormat $format, array $media, ?string $link = null, ?string $caption = 'Halloween'): void
    {
        $this->expectException(InvalidSocialPostException::class);

        (new SocialPostValidator)->validate($this->postIn($network, $format, $media, $link, $caption), $this->connection());
    }

    public function test_instagram_caption_length_is_limited(): void
    {
        $this->expectException(InvalidSocialPostException::class);

        (new SocialPostValidator)->validate(
            $this->postIn(SocialNetwork::INSTAGRAM, SocialPostFormat::IMAGE, [['https://cdn.test/a.jpg', 'IMAGE']], null, str_repeat('a', 2201)),
            $this->connection(),
        );
    }

    private function postIn(SocialNetwork $network, SocialPostFormat $format, array $media, ?string $link, ?string $caption): SocialPostDataDTO
    {
        return new SocialPostDataDTO(
            network: $network,
            format: $format,
            caption: $caption,
            media: array_map(fn (array $item) => new SocialMediaItemDTO($item[0], SocialMediaType::fromName($item[1])), $media),
            link: $link,
            scheduledAt: null,
        );
    }

    private function connection(): OrganizerSocialConnectionDomainObject
    {
        return (new OrganizerSocialConnectionDomainObject)->setInstagramAccountId('1784');
    }
}
