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

    public function test_questions_are_not_reactions(): void
    {
        foreach (['Bonjour', 'Ok merci 👍', 'Aimée a payé ?', 'Liked it, how much is it?x'] as $content) {
            self::assertFalse(SmsReactionDetector::isReaction($content), $content);
        }
    }
}
