<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\Models\Event;
use HiEvents\Services\Domain\LiveChat\Knowledge\EventAssistantKnowledgeService;
use HiEvents\Services\Domain\LiveChat\LiveChatReplyGenerator;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\LiveChat\Question\LiveChatQuestionService;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class EventAssistantKnowledgeTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_fact_command_adds_lists_and_removes_facts(): void
    {
        /** @var Event $event */
        [$event] = $this->createEventWithProduct();

        $this->artisan('live-chat:fact', ['event' => $event->id, '--add' => 'Jah D One a été remplacé par Terrell Elymoor'])
            ->assertSuccessful();

        $fact = DB::table('event_assistant_facts')->where('event_id', $event->id)->first();
        $this->assertSame('Jah D One a été remplacé par Terrell Elymoor', $fact->answer);
        $this->assertSame('ADMIN', $fact->source);
        $this->assertSame((int) $event->organizer_id, (int) $fact->organizer_id);
        $this->assertNotNull($fact->confirmed_at);

        $knowledge = app(EventAssistantKnowledgeService::class)->forEvents((int) $event->organizer_id, [$event->id]);
        $this->assertSame('Jah D One a été remplacé par Terrell Elymoor', $knowledge[$event->id]['faits_confirmes'][0]['reponse']);

        $this->artisan('live-chat:fact', ['event' => $event->id, '--list' => true])->assertSuccessful();
        $this->artisan('live-chat:fact', ['event' => $event->id, '--remove' => $fact->id])->assertSuccessful();
        $this->assertSame(0, DB::table('event_assistant_facts')->where('event_id', $event->id)->count());

        $this->artisan('live-chat:fact', ['event' => 999999, '--list' => true])->assertFailed();
        $this->artisan('live-chat:fact', ['event' => $event->id])->assertFailed();
    }

    public function test_changes_of_the_event_are_kept_with_the_former_value(): void
    {
        /** @var Event $event */
        [$event] = $this->createEventWithProduct();
        $service = app(EventAssistantKnowledgeService::class);

        $this->assertSame(0, $service->recordChanges((int) $event->organizer_id, $this->info($event->id, ['Le D', 'Jah D One'], 'Soirée Halloween.')));
        $this->assertSame(0, $service->recordChanges((int) $event->organizer_id, $this->info($event->id, ['Le D', 'Jah D One'], 'Soirée Halloween.')));
        $this->assertSame(2, $service->recordChanges((int) $event->organizer_id, $this->info($event->id, ['Le D', 'Terrell Elymoor'], 'Soirée Halloween au 5/5.')));

        $changes = DB::table('event_assistant_facts')->where('event_id', $event->id)->orderBy('id')->pluck('answer')->all();
        $this->assertSame([
            'Changement pour les artistes, avant : Le D, Jah D One. Maintenant : Le D, Terrell Elymoor',
            'La description a changé. Ancienne version : Soirée Halloween.',
        ], $changes);
        $this->assertSame(['CHANGE'], DB::table('event_assistant_facts')->where('event_id', $event->id)->distinct()->pluck('source')->all());

        $knowledge = $service->forEvents((int) $event->organizer_id, [$event->id]);
        $this->assertCount(2, $knowledge[$event->id]['changements']);
        $this->assertArrayNotHasKey('faits_confirmes', $knowledge[$event->id]);
    }

    public function test_sent_sms_campaigns_of_the_event_are_announcements(): void
    {
        /** @var Event $event */
        [$event] = $this->createEventWithProduct();
        $base = [
            'account_id' => $event->account_id,
            'organizer_id' => $event->organizer_id,
            'event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('sms_campaigns')->insert([
            ...$base, 'name' => 'Envoyée', 'message' => 'Hello {p} ! Terrell Elymoor remplace Jah D One samedi.', 'started_at' => now(),
        ]);
        DB::table('sms_campaigns')->insert([...$base, 'name' => 'Brouillon', 'message' => 'Pas encore partie', 'started_at' => null]);

        $knowledge = app(EventAssistantKnowledgeService::class)->forEvents((int) $event->organizer_id, [$event->id]);

        $this->assertCount(1, $knowledge[$event->id]['annonces']);
        $this->assertSame('Hello ! Terrell Elymoor remplace Jah D One samedi.', $knowledge[$event->id]['annonces'][0]['texte']);
    }

    public function test_missing_information_sentences_are_detected(): void
    {
        foreach ([
            "Je n'ai pas l'info pour Jah D One.",
            "Je n’ai pas d'information là-dessus.",
            'Je ne sais pas encore.',
            'Je ne peux pas te dire s\'il sera là.',
            'Je ne peux pas vous le confirmer.',
            "Je n'ai aucune info.",
            'Pas d\'info pour le moment.',
            'Je ne suis pas sûre.',
        ] as $sentence) {
            $this->assertTrue(LiveChatReplyGenerator::saysMissingInfo($sentence), $sentence);
        }

        foreach ([
            'Le D est bien au programme samedi.',
            "Je t'envoie l'info dès que j'ai la confirmation.",
            'Les infos pratiques sont sur la page.',
        ] as $sentence) {
            $this->assertFalse(LiveChatReplyGenerator::saysMissingInfo($sentence), $sentence);
        }

        $this->assertSame(
            [['Pas de souci, c\'est ton choix. Si tu veux prendre ta place, c\'est sur innocent976.yt.', 'Je demande confirmation et je te redis ici.'], true],
            LiveChatReplyGenerator::withoutMissingInfo(
                ["Pas de souci, c'est ton choix. Je n'ai pas l'info pour Jah D One, donc je ne peux pas te dire s'il sera là. Si tu veux prendre ta place, c'est sur innocent976.yt."],
                true,
                1,
            ),
        );
        $this->assertSame([['Le D joue à 22h.'], false], LiveChatReplyGenerator::withoutMissingInfo(['Le D joue à 22h.'], true));
    }

    public function test_question_references_and_acknowledgements(): void
    {
        $this->assertSame([12, 'il est remplacé'], LiveChatQuestionService::parseReference('Q12 il est remplacé'));
        $this->assertSame([12, 'oui'], LiveChatQuestionService::parseReference(' q 12 : oui'));
        $this->assertNull(LiveChatQuestionService::parseReference('Quoi ?'));
        $this->assertFalse(LiveChatQuestionService::isAnswer('ok'));
        $this->assertFalse(LiveChatQuestionService::isAnswer('Merci !'));
        $this->assertFalse(LiveChatQuestionService::isAnswer('👍'));
        $this->assertTrue(LiveChatQuestionService::isAnswer('non'));
        $this->assertTrue(LiveChatQuestionService::isAnswer('Il est remplacé par Terrell'));
    }

    public function test_contact_rules(): void
    {
        $this->assertFalse(LiveChatService::contactAllowed(1, 'jah d one ne sera pas présent ?', false, false));
        $this->assertFalse(LiveChatService::contactAllowed(2, 'non', false, false));
        $this->assertTrue(LiveChatService::contactAllowed(3, 'et alors', false, false));
        $this->assertFalse(LiveChatService::contactAllowed(5, 'et alors', false, true));
        $this->assertFalse(LiveChatService::contactAllowed(5, 'et alors', true, false));
        $this->assertTrue(LiveChatService::contactAllowed(1, 'Vous pouvez me rappeler ?', false, false));
        $this->assertTrue(LiveChatService::contactAllowed(1, 'mon numéro 0639 12 34 56', false, false));
        $this->assertTrue(LiveChatService::refusesContact('non'));
        $this->assertTrue(LiveChatService::refusesContact('Non merci'));
        $this->assertTrue(LiveChatService::refusesContact('je préfère pas'));
        $this->assertFalse(LiveChatService::refusesContact('Normalement oui'));
    }

    private function info(int $eventId, array $artists, string $description): SmsAssistantEventInfoDTO
    {
        return new SmsAssistantEventInfoDTO(
            title: 'Halloween',
            startsAt: '2026-10-31 20:00',
            endsAt: null,
            venue: '5/5, Mamoudzou',
            url: 'https://innocent976.yt',
            tickets: [],
            id: $eventId,
            description: $description,
            artists: $artists,
        );
    }
}
