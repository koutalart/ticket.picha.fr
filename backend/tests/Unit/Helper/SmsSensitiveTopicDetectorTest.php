<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use HiEvents\Helper\SmsSensitiveTopicDetector;
use Tests\TestCase;

class SmsSensitiveTopicDetectorTest extends TestCase
{
    public function test_sensitive_topics_are_detected(): void
    {
        foreach ([
            'complaint' => ['Je veux faire une réclamation', 'RECLAMATION', 'Je ne suis pas content, c\'est une arnaque', 'Toujours pas reçu mon billet'],
            'refund' => ['Je veux être remboursé', 'Remboursement svp', 'Je dois annuler ma commande'],
            'invitation' => ['Vous avez des invitations ?', 'Je peux être sur la liste des invités', 'C\'est gratuit pour moi ?'],
            'place_request' => ['Tu peux me garder 2 places', 'Une place pour moi stp'],
            'press' => ['Je suis journaliste à Mayotte la 1ère', 'Demande presse', 'Pour un reportage télé'],
            'minor' => ['Les mineurs sont acceptés ?', 'Je viens avec mon enfant', 'Mon fils a 15 ans'],
            'accessibility' => ['Accès PMR ?', 'Je suis en fauteuil', 'Personne en situation de handicap'],
        ] as $topic => $contents) {
            foreach ($contents as $content) {
                self::assertSame($topic, SmsSensitiveTopicDetector::detect($content), $content);
            }
        }
    }

    public function test_simple_questions_are_not_sensitive(): void
    {
        foreach ([
            'Il reste des places ?',
            'Ça commence à quelle heure ?',
            'Combien coûte la place ?',
            'C\'est où exactement ?',
            'Je peux acheter sur place ?',
            'Le concert des 25 ans de Konka Wawa c\'est quand ?',
            '',
        ] as $content) {
            self::assertNull(SmsSensitiveTopicDetector::detect($content), $content);
        }
    }
}
