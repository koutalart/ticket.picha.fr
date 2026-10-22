<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use HiEvents\Helper\SmsAcknowledgementDetector;
use Tests\TestCase;

class SmsAcknowledgementDetectorTest extends TestCase
{
    public function test_acknowledgements_are_detected_whatever_the_case_and_accents(): void
    {
        foreach ([
            'ok', 'OK', 'Ok merci', 'Okkk', 'Merci', 'MERCI !', 'merciii', 'Merci beaucoup', 'merci bcp 🙏',
            "D'accord", 'd’accord', 'Daccord', 'dac', '👍', 'Super', 'Super merci 😊', 'Top', 'Parfait',
            'Ça marche', 'ca marche merci', 'Génial', 'Très bien merci', 'Bien reçu', 'Oui merci', 'Thanks',
            'Ok cool', 'Nickel, bonne soirée',
        ] as $content) {
            self::assertTrue(SmsAcknowledgementDetector::isAcknowledgement($content), $content);
        }
    }

    public function test_questions_and_longer_messages_are_not_acknowledgements(): void
    {
        foreach ([
            'Ok mais ça commence à quelle heure ?',
            'Merci ?',
            'Oui',
            'Il reste des places',
            'Super, je peux venir avec deux amis',
            'Merci pour tout, je voulais savoir si le parking est gratuit le soir',
            'Ok le prix',
            '',
        ] as $content) {
            self::assertFalse(SmsAcknowledgementDetector::isAcknowledgement($content), $content);
        }
    }
}
