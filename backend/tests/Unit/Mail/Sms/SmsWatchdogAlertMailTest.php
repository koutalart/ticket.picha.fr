<?php

namespace Tests\Unit\Mail\Sms;

use HiEvents\DomainObjects\Enums\SmsWatchdogAnomalyType;
use HiEvents\Mail\Sms\SmsWatchdogAlertMail;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogAnomalyDTO;
use Tests\TestCase;

class SmsWatchdogAlertMailTest extends TestCase
{
    public function test_it_lists_anomalies_in_french_with_masked_phones_and_mayotte_time(): void
    {
        $mail = (new SmsWatchdogAlertMail([
            new SmsWatchdogAnomalyDTO(
                type: SmsWatchdogAnomalyType::UNANSWERED_INBOUND,
                key: 'conversation:12:inbound:340',
                organizerId: 8,
                organizerName: 'Kultur\'m',
                conversationId: 12,
                phone: '+262639250206',
                occurredAt: '2026-10-23 09:30:00',
                excerpt: 'Il reste des places ?',
                thresholdMinutes: 10,
            ),
            new SmsWatchdogAnomalyDTO(
                type: SmsWatchdogAnomalyType::DRAFT_PENDING,
                key: 'conversation:20:draft:20261023093000',
                organizerId: 4,
                organizerName: 'Innocent Event',
                conversationId: 20,
                phone: '+262639000002',
                occurredAt: '2026-10-23 09:30:00',
                thresholdMinutes: 10,
            ),
            new SmsWatchdogAnomalyDTO(
                type: SmsWatchdogAnomalyType::DUPLICATE_REPLY,
                key: 'inbound:341',
                organizerId: 8,
                organizerName: 'Kultur\'m',
                conversationId: 13,
                phone: '+262639000001',
                occurredAt: '2026-10-23 10:00:00',
                count: 2,
            ),
        ]))->locale('fr');

        $html = $mail->render();

        $mail->assertHasSubject('Surveillance SMS : 3 anomalie(s)');
        $this->assertSame(1, substr_count($html, '(#8)'));
        $this->assertSame(1, substr_count($html, 'Innocent Event (#4)'));
        $this->assertLessThan(strpos($html, 'Innocent Event (#4)'), strpos($html, 'Réponse en double'));
        $this->assertStringContainsString('SMS sans réponse', $html);
        $this->assertStringContainsString('Sans réponse depuis plus de 10 minutes', $html);
        $this->assertStringContainsString('Brouillon non validé depuis plus de 10 minutes', $html);
        $this->assertStringContainsString('Réponse en double', $html);
        $this->assertStringContainsString('2 réponses au même SMS', $html);
        $this->assertStringContainsString('********0206', $html);
        $this->assertStringNotContainsString('639250206', $html);
        $this->assertStringContainsString('23/10/2026 12:30', $html);
        $this->assertStringContainsString('#12', $html);
        $this->assertStringContainsString('Il reste des places ?', $html);
        foreach (['•', '·', '—', '–'] as $character) {
            $this->assertStringNotContainsString($character, $html);
        }
    }

    public function test_phone_masking_keeps_only_the_last_four_digits(): void
    {
        $this->assertSame('********0206', SmsWatchdogAlertMail::maskPhone('+262 639 25 02 06'));
        $this->assertSame('***', SmsWatchdogAlertMail::maskPhone('123'));
        $this->assertSame('?', SmsWatchdogAlertMail::maskPhone(null));
    }
}
