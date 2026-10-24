<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\SocialPostService;
use Illuminate\Console\Command;
use Throwable;
use ValueError;

class CreateSocialPostCommand extends Command
{
    protected $signature = 'social:create-post
        {organizer : ID of the organizer}
        {--network=* : FACEBOOK and/or INSTAGRAM}
        {--format= : TEXT, IMAGE, CAROUSEL, REEL or STORY}
        {--caption= : Text of the post}
        {--caption-file= : File holding the text of the post}
        {--image=* : Public https:// link of an image (repeat for a carousel)}
        {--video= : Public https:// link of a video (reel or story)}
        {--link= : Link of a Facebook text post}
        {--at= : Publication time, for example "2026-10-31 19:00" (Mayotte time unless an offset is given)}';

    protected $description = 'Prepare a Facebook / Instagram post for an organizer. It waits for a person to approve it in PICHA or with social:approve-post.';

    private const DEFAULT_TIMEZONE = 'Indian/Mayotte';

    public function handle(SocialPostService $postService, OrganizerRepositoryInterface $organizerRepository): int
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $organizerRepository->findFirstWhere(['id' => (int) $this->argument('organizer')]);

        if ($organizer === null) {
            $this->error('Organizer not found.');

            return self::FAILURE;
        }

        try {
            $networks = array_map(fn (string $network) => SocialNetwork::from(strtoupper(trim($network))), (array) $this->option('network'));
            $format = SocialPostFormat::from(strtoupper(trim((string) $this->option('format'))));
        } catch (ValueError) {
            $this->error('Use --network=FACEBOOK and/or --network=INSTAGRAM, and --format=TEXT, IMAGE, CAROUSEL, REEL or STORY.');

            return self::FAILURE;
        }

        $caption = $this->readCaption();

        if ($caption === false) {
            $this->error('The caption file cannot be read.');

            return self::FAILURE;
        }

        try {
            $posts = $postService->create(new CreateSocialPostsDTO(
                accountId: $organizer->getAccountId(),
                organizerId: $organizer->getId(),
                userId: null,
                networks: $networks,
                format: $format,
                caption: $caption,
                media: $this->media(),
                link: $this->option('link') ? (string) $this->option('link') : null,
                scheduledAt: $this->option('at') ? Carbon::parse((string) $this->option('at'), self::DEFAULT_TIMEZONE)->utc() : null,
                source: SocialPostSource::ASSISTANT,
            ));
        } catch (InvalidSocialPostException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('The post could not be saved: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Network', 'Format', 'Status', 'Scheduled at (UTC)'],
            $posts->map(fn (SocialPostDomainObject $post) => [
                $post->getId(),
                $post->getNetwork(),
                $post->getFormat(),
                $post->getStatus(),
                $post->getScheduledAt() ?? 'as soon as approved',
            ])->all(),
        );
        $this->info('Nothing is published until a person approves it (Publications page in PICHA, or social:approve-post).');

        return self::SUCCESS;
    }

    private function readCaption(): string|false|null
    {
        $file = $this->option('caption-file');

        if ($file) {
            return is_readable((string) $file) ? file_get_contents((string) $file) : false;
        }

        return $this->option('caption') !== null ? (string) $this->option('caption') : null;
    }

    /**
     * @return SocialMediaItemDTO[]
     */
    private function media(): array
    {
        $media = array_map(
            fn (string $url) => new SocialMediaItemDTO(url: trim($url), type: SocialMediaType::IMAGE),
            (array) $this->option('image'),
        );

        if ($this->option('video')) {
            $media[] = new SocialMediaItemDTO(url: trim((string) $this->option('video')), type: SocialMediaType::VIDEO);
        }

        return $media;
    }
}
