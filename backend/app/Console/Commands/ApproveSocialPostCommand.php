<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\SocialPostService;
use Illuminate\Console\Command;

class ApproveSocialPostCommand extends Command
{
    protected $signature = 'social:approve-post
        {post : ID of the post}
        {--user= : ID of the PICHA user who approves the post}
        {--yes : Approve without asking for confirmation}';

    protected $description = 'Approve a Facebook / Instagram post waiting for approval: it is published at its scheduled time, or right away.';

    public function __construct(
        private readonly SocialPostService $postService,
        private readonly SocialPostRepositoryInterface $postRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var SocialPostDomainObject|null $post */
        $post = $this->postRepository->findFirstWhere([SocialPostDomainObjectAbstract::ID => (int) $this->argument('post')]);

        if ($post === null) {
            $this->error('Post not found.');

            return self::FAILURE;
        }

        if ($post->getStatus() !== SocialPostStatus::PENDING_APPROVAL->name && $post->getStatus() !== SocialPostStatus::DRAFT->name) {
            $this->error(sprintf('This post is %s: only a post waiting for approval or a draft can be approved here.', $post->getStatus()));

            return self::FAILURE;
        }

        $this->line(sprintf('Organizer %d, %s %s', $post->getOrganizerId(), $post->getNetwork(), $post->getFormat()));
        $this->line('Scheduled at (UTC): '.($post->getScheduledAt() ?? 'right away'));
        $this->line('Caption: '.($post->getCaption() ?? '(none)'));

        foreach ($this->postService->mediaFromPost($post) as $item) {
            $this->line(sprintf('Media (%s): %s', $item->type->name, $item->url));
        }

        if (! $this->option('yes') && ! $this->confirm('Publish this post?')) {
            $this->info('Nothing was approved.');

            return self::SUCCESS;
        }

        try {
            $approved = $this->postService->schedule(
                $post->getOrganizerId(),
                $post->getId(),
                $this->option('user') ? (int) $this->option('user') : null,
            );
        } catch (SocialPostNotEditableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Post %d approved: it will be published from %s (UTC).', $approved->getId(), $approved->getNextAttemptAt()));

        return self::SUCCESS;
    }
}
