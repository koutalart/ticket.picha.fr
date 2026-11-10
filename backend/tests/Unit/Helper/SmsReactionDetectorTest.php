<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use HiEvents\Helper\SmsReactionDetector;
use Tests\TestCase;

class SmsReactionDetectorTest extends TestCase
{
    public function test_reactions_are_detected(): void
    {
        foreach (['👍', ' ❤️ ', '😂😂', 'A réagi avec 👍 à « Bonsoir »', 'A aimé « Halloween arrive »', 'Liked “See you”', 'Loved "ok"', '...'] as $content) {
            self::assertTrue(SmsReactionDetector::isReaction($content), $content);
        }
    }

    public function test_french_tapbacks_are_detected(): void
    {
        $reactions = [
            'A ajouté un « J\'adore » à « Bonjour Roukia, c\'est Anicka… »',
            'A ajouté un ❤️ à « Bonjour Roukia »',
            'A ajouté une réaction 😂 à « On se voit samedi »',
            'A aimé « Les inscriptions sont ouvertes »',
            'a adoré “Merci pour hier”',
            'A ri de « Le DJ arrive en retard »',
            'A souligné « Rendez-vous à 18h »',
            'A mis en doute « Tout est prêt ? »',
            'N\'a pas aimé « Le concert est annulé »',
            'N’a pas aimé « Le concert est annulé »',
            'A retiré un « J\'aime » de « Bonjour »',
            'A retiré un J’adore de « Bonjour »',
        ];

        foreach ($reactions as $content) {
            self::assertTrue(SmsReactionDetector::isReaction($content), $content);
        }
    }

    public function test_english_tapbacks_are_detected(): void
    {
        $reactions = [
            'Liked “Doors open at 6pm”',
            'Loved "See you Saturday"',
            'Laughed at “The DJ is late”',
            'Emphasized “Meet at 6”',
            'Questioned “All set?”',
            'Disliked “The show is cancelled”',
            'Reacted 😂 to “The DJ is late”',
            'Removed a heart from “See you”',
        ];

        foreach ($reactions as $content) {
            self::assertTrue(SmsReactionDetector::isReaction($content), $content);
        }
    }

    public function test_questions_are_not_reactions(): void
    {
        $messages = [
            'Bonjour',
            'Ok merci 👍',
            'Aimée a payé ?',
            'Liked it, how much is it?x',
            'A aimé « le concert », on peut avoir 2 places ?',
            'Tu as ajouté une place à « Halloween » ?',
            'A ajouté une place à « Halloween », c\'est bon ?',
            'Loved the show, when is the next one?',
        ];

        foreach ($messages as $content) {
            self::assertFalse(SmsReactionDetector::isReaction($content), $content);
        }
    }
}
