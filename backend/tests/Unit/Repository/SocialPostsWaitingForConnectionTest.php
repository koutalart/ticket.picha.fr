<?php

namespace Tests\Unit\Repository;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Models\Organizer;
use HiEvents\Models\OrganizerSocialConnection;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\SocialPostService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SocialPostsWaitingForConnectionTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_post_approved_without_a_page_waits_then_leaves_right_away_once_connected(): void
    {
        Carbon::setTestNow('2026-11-04 08:00:00');
        $organizer = $this->organizer();

        $post = $this->approvedPost($organizer, scheduledAt: null);

        $this->assertSame(SocialPostStatus::SCHEDULED->name, $post->getStatus());
        $this->assertNotContains($post->getId(), $this->dueIds());

        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->assertNotContains($post->getId(), $this->dueIds());

        $this->connect($organizer);

        $this->assertContains($post->getId(), $this->dueIds());
    }

    public function test_post_approved_without_a_page_for_a_future_time_leaves_at_that_time_once_connected(): void
    {
        Carbon::setTestNow('2026-11-04 08:00:00');
        $organizer = $this->organizer();

        $post = $this->approvedPost($organizer, scheduledAt: Carbon::parse('2026-11-04 18:00:00'));
        $this->connect($organizer);

        $this->assertNotContains($post->getId(), $this->dueIds());

        Carbon::setTestNow('2026-11-04 18:00:00');
        $this->assertContains($post->getId(), $this->dueIds());
    }

    public function test_post_waits_while_the_connection_must_be_renewed(): void
    {
        Carbon::setTestNow('2026-11-04 08:00:00');
        $organizer = $this->organizer();

        $post = $this->approvedPost($organizer, scheduledAt: null);
        $this->connect($organizer, SocialConnectionStatus::NEEDS_RECONNECT);

        $this->assertNotContains($post->getId(), $this->dueIds());
    }

    private function organizer(): Organizer
    {
        [$event] = $this->createEventWithProduct();

        return Organizer::findOrFail($event->organizer_id);
    }

    private function approvedPost(Organizer $organizer, ?Carbon $scheduledAt): SocialPostDomainObject
    {
        $service = $this->app->make(SocialPostService::class);

        $created = $service->create(new CreateSocialPostsDTO(
            accountId: $organizer->account_id,
            organizerId: $organizer->id,
            userId: null,
            networks: [SocialNetwork::INSTAGRAM],
            format: SocialPostFormat::IMAGE,
            caption: 'Soirée El Djine',
            media: [new SocialMediaItemDTO('https://cdn.test/affiche.jpg', SocialMediaType::IMAGE)],
            link: null,
            scheduledAt: $scheduledAt,
            source: SocialPostSource::ASSISTANT,
        ))->first();

        $this->assertSame(SocialPostStatus::PENDING_APPROVAL->name, $created->getStatus());

        return $service->schedule($organizer->id, $created->getId(), null);
    }

    private function connect(Organizer $organizer, SocialConnectionStatus $status = SocialConnectionStatus::ACTIVE): void
    {
        OrganizerSocialConnection::create([
            'organizer_id' => $organizer->id,
            'account_id' => $organizer->account_id,
            'status' => $status->name,
            'facebook_page_id' => '258426924559094',
            'page_access_token' => 'page-token',
            'instagram_account_id' => '1784',
        ]);
    }

    /**
     * @return int[]
     */
    private function dueIds(): array
    {
        return $this->app->make(SocialPostRepositoryInterface::class)
            ->findDueForPublishing(100)
            ->map(fn (SocialPostDomainObject $post) => $post->getId())
            ->all();
    }
}
