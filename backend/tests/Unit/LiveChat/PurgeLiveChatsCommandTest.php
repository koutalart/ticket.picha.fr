<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\Models\Event;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class PurgeLiveChatsCommandTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_old_chats_without_contact_are_deleted_and_the_others_kept(): void
    {
        /** @var Event $event */
        [$event] = $this->createEventWithProduct();

        $oldAnonymous = $this->chatSession($event, now()->subDays(91), null, null);
        $recentAnonymous = $this->chatSession($event, now()->subDays(10), null, null);
        $oldWithPhone = $this->chatSession($event, now()->subDays(200), '+262639123456', null);
        $oldWithEmail = $this->chatSession($event, now()->subDays(200), null, 'visiteur@example.test');
        DB::table('live_chat_messages')->insert([
            'live_chat_session_id' => $oldAnonymous,
            'author_type' => 'VISITOR',
            'content' => 'Bonjour',
            'created_at' => now()->subDays(91),
            'updated_at' => now()->subDays(91),
        ]);

        $this->artisan('live-chat:purge')->assertSuccessful();

        $remaining = DB::table('live_chat_sessions')->where('organizer_id', $event->organizer_id)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$recentAnonymous, $oldWithPhone, $oldWithEmail], $remaining);
        $this->assertSame(0, DB::table('live_chat_messages')->where('live_chat_session_id', $oldAnonymous)->count());
    }

    private function chatSession(Event $event, $lastMessageAt, ?string $phone, ?string $email): int
    {
        return DB::table('live_chat_sessions')->insertGetId([
            'public_id' => Str::random(48),
            'account_id' => $event->account_id,
            'organizer_id' => $event->organizer_id,
            'status' => $phone || $email ? 'HANDED_OFF' : 'OPEN',
            'visitor_phone' => $phone,
            'visitor_email' => $email,
            'last_message_at' => $lastMessageAt,
            'created_at' => $lastMessageAt,
            'updated_at' => $lastMessageAt,
        ]);
    }
}
