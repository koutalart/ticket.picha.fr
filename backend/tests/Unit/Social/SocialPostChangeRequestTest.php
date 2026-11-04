<?php

namespace Tests\Unit\Social;

use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Models\Event;
use HiEvents\Models\SocialPost;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\SocialPostService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SocialPostChangeRequestTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'Password-social-1';

    private Event $event;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event, , , $user] = $this->createEventWithProduct(userPassword: self::PASSWORD);
        $this->token = $this->loginAndGetToken($user, self::PASSWORD);
    }

    public function test_organizer_requests_a_change_with_a_comment(): void
    {
        $post = $this->postToApprove();

        $this->requestChanges($post->id, '  Mets plutôt l\'affiche <b>verte</b>  ')
            ->assertOk()
            ->assertJsonPath('data.status', SocialPostStatus::CHANGES_REQUESTED->name)
            ->assertJsonPath('data.change_request_comment', 'Mets plutôt l\'affiche verte');

        $post->refresh();
        $this->assertNotNull($post->change_requested_at);
        $this->assertNotNull($post->change_requested_by_user_id);
    }

    public function test_comment_must_have_between_3_and_1000_characters(): void
    {
        $post = $this->postToApprove();

        $this->requestChanges($post->id, ' ok ')->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->requestChanges($post->id, str_repeat('a', 1001))->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->requestChanges($post->id, '')->assertUnprocessable()->assertJsonValidationErrors('comment');

        $this->assertSame(SocialPostStatus::PENDING_APPROVAL->name, $post->refresh()->status);
    }

    public function test_changes_can_only_be_requested_on_a_post_to_approve(): void
    {
        $post = $this->postToApprove();
        $post->update(['status' => SocialPostStatus::SCHEDULED->name]);

        $this->requestChanges($post->id, 'Change le texte')->assertStatus(409);

        $this->assertSame(SocialPostStatus::SCHEDULED->name, $post->refresh()->status);
    }

    public function test_another_account_cannot_request_changes(): void
    {
        $post = $this->postToApprove();
        $intruder = $this->createUnrelatedOrganizerUser(self::PASSWORD);
        $intruderToken = $this->loginAndGetToken($intruder, self::PASSWORD);

        $this->postJson(
            "/organizers/{$this->event->organizer_id}/social/posts/{$post->id}/request-changes",
            ['comment' => 'Change le texte'],
            ['Authorization' => 'Bearer '.$intruderToken],
        )->assertForbidden();
    }

    public function test_assistant_lists_the_requests_and_replaces_the_post_with_a_new_version(): void
    {
        $post = $this->postToApprove();
        $this->requestChanges($post->id, 'Ajoute la date en gros')->assertOk();

        Artisan::call('social:list-change-requests', ['organizer' => $this->event->organizer_id, '--json' => true]);
        $requests = json_decode(Artisan::output(), true);

        $this->assertSame([[
            'id' => $post->id,
            'comment' => 'Ajoute la date en gros',
            'caption' => 'Soirée El Djine',
        ]], array_map(fn (array $request) => array_intersect_key($request, array_flip(['id', 'comment', 'caption'])), $requests));
        $this->assertNotNull($requests[0]['requested_at']);

        $this->artisan('social:create-post', [
            'organizer' => $this->event->organizer_id,
            '--network' => ['INSTAGRAM'],
            '--format' => 'IMAGE',
            '--caption' => 'Soirée El Djine, samedi 8 novembre',
            '--image' => ['https://cdn.test/affiche-v2.jpg'],
            '--replaces' => [(string) $post->id],
        ])->assertSuccessful();

        $new = SocialPost::query()->where('organizer_id', $this->event->organizer_id)->whereKeyNot($post->id)->sole();
        $post->refresh();

        $this->assertSame(SocialPostStatus::PENDING_APPROVAL->name, $new->status);
        $this->assertSame(SocialPostStatus::CANCELLED->name, $post->status);
        $this->assertSame($new->id, $post->replaced_by_post_id);
        $this->assertSame('Ajoute la date en gros', $post->change_request_comment);

        $this->artisan('social:list-change-requests', ['organizer' => $this->event->organizer_id])
            ->expectsOutputToContain('No change request.')
            ->assertSuccessful();
    }

    public function test_a_published_post_cannot_be_replaced(): void
    {
        $post = $this->postToApprove();
        $post->update(['status' => SocialPostStatus::PUBLISHED->name]);

        $this->artisan('social:create-post', [
            'organizer' => $this->event->organizer_id,
            '--network' => ['INSTAGRAM'],
            '--format' => 'IMAGE',
            '--image' => ['https://cdn.test/affiche-v2.jpg'],
            '--replaces' => [(string) $post->id],
        ])->assertFailed();

        $this->assertSame(1, SocialPost::query()->where('organizer_id', $this->event->organizer_id)->count());
        $this->assertSame(SocialPostStatus::PUBLISHED->name, $post->refresh()->status);
    }

    private function postToApprove(): SocialPost
    {
        $created = $this->app->make(SocialPostService::class)->create(new CreateSocialPostsDTO(
            accountId: $this->event->account_id,
            organizerId: $this->event->organizer_id,
            userId: null,
            networks: [SocialNetwork::INSTAGRAM],
            format: SocialPostFormat::IMAGE,
            caption: 'Soirée El Djine',
            media: [new SocialMediaItemDTO('https://cdn.test/affiche.jpg', SocialMediaType::IMAGE)],
            link: null,
            scheduledAt: null,
            source: SocialPostSource::ASSISTANT,
        ))->first();

        return SocialPost::findOrFail($created->getId());
    }

    private function requestChanges(int $postId, string $comment)
    {
        return $this->postJson(
            "/organizers/{$this->event->organizer_id}/social/posts/{$postId}/request-changes",
            ['comment' => $comment],
            ['Authorization' => 'Bearer '.$this->token],
        );
    }
}
