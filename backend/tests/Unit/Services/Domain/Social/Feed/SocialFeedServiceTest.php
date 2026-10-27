<?php

namespace Tests\Unit\Services\Domain\Social\Feed;

use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\Feed\DTO\SocialFeedEntryDTO;
use HiEvents\Services\Domain\Social\Feed\SocialFeedService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class SocialFeedServiceTest extends TestCase
{
    public function test_merges_both_sources_by_date_and_shows_a_published_picha_post_once(): void
    {
        $published = (new SocialPostDomainObject)->setId(1)->setStatus('PUBLISHED')->setPublishedAt('2026-10-23 16:00:00')->setCreatedAt('2026-10-20 10:00:00');
        $scheduled = (new SocialPostDomainObject)->setId(2)->setStatus('SCHEDULED')->setScheduledAt('2026-10-30 16:00:00')->setCreatedAt('2026-10-24 10:00:00');
        $pending = (new SocialPostDomainObject)->setId(3)->setStatus('PENDING_APPROVAL')->setCreatedAt('2026-10-24 12:00:00');

        $items = Mockery::mock(SocialFeedItemRepositoryInterface::class);
        $items->shouldReceive('findLatestForOrganizer')->with(4, 21)->andReturn(new Collection([
            (new SocialFeedItemDomainObject)->setId(10)->setSocialPostId(1)->setPublishedAt('2026-10-23 16:01:00')->setCommentsCount(1),
            (new SocialFeedItemDomainObject)->setId(11)->setPublishedAt('2026-10-22 09:00:00')->setCommentsCount(0),
        ]));
        $posts = Mockery::mock(SocialPostRepositoryInterface::class);
        $posts->shouldReceive('paginateForOrganizer')->with(4, null, 21)
            ->andReturn(new LengthAwarePaginator([$scheduled, $pending, $published], 3, 21));

        $comments = Mockery::mock(SocialCommentRepositoryInterface::class);
        $comments->shouldReceive('findLatestTopLevelForFeedItems')->with([10], 2)->andReturn(new Collection([
            10 => new Collection([(new SocialCommentDomainObject)->setId(5)->setMessage('Trop hâte')]),
        ]));

        $entries = (new SocialFeedService($posts, $items, $comments))->entries(4, 20);

        $this->assertSame(
            ['picha-2', 'picha-3', 'network-10', 'network-11'],
            $entries->map(fn (SocialFeedEntryDTO $entry) => $entry->item ? 'network-'.$entry->item->getId() : 'picha-'.$entry->post->getId())->all(),
        );
        $this->assertSame(1, $entries[2]->post?->getId());
        $this->assertSame('Trop hâte', $entries[2]->latestComments->first()->getMessage());
        $this->assertCount(0, $entries[3]->latestComments);
    }
}
