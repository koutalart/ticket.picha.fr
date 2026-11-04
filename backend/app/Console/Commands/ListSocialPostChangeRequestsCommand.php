<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Services\Domain\Social\SocialPostService;
use Illuminate\Console\Command;

class ListSocialPostChangeRequestsCommand extends Command
{
    protected $signature = 'social:list-change-requests
        {organizer? : ID of the organizer (all organizers when left out)}
        {--json : Print the requests as JSON}';

    protected $description = 'List the Facebook / Instagram posts on which the organizer asked for changes. Propose a new version with social:create-post --replaces=ID.';

    public function handle(SocialPostService $postService): int
    {
        $organizerId = $this->argument('organizer') !== null ? (int) $this->argument('organizer') : null;
        $requests = $postService->findChangeRequests($organizerId)->map(fn (SocialPostDomainObject $post) => [
            'id' => $post->getId(),
            'organizer_id' => $post->getOrganizerId(),
            'network' => $post->getNetwork(),
            'format' => $post->getFormat(),
            'requested_at' => $post->getChangeRequestedAt(),
            'comment' => $post->getChangeRequestComment(),
            'caption' => $post->getCaption(),
            'scheduled_at' => $post->getScheduledAt(),
        ])->values();

        if ($this->option('json')) {
            $this->line((string) json_encode($requests->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($requests->isEmpty()) {
            $this->info('No change request.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Organizer', 'Network', 'Format', 'Requested at (UTC)', 'Comment', 'Current caption'],
            $requests->map(fn (array $request) => [
                $request['id'],
                $request['organizer_id'],
                $request['network'],
                $request['format'],
                $request['requested_at'],
                $request['comment'],
                $request['caption'] ?? '(none)',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
