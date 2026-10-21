<?php

namespace Tests\Unit\Mail\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Mail\Sms\SmsInboundAlertMail;
use HiEvents\Mail\Sms\SmsPhoneOfflineAlertMail;
use Tests\TestCase;

class SmsAlertMailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://ticket.example.test']);
    }

    public function test_inbound_alert_mail_renders_messages_in_french(): void
    {
        $mail = (new SmsInboundAlertMail(
            $this->organizer(),
            (new SmsConversationDomainObject)->setId(3)->setPhone('+262639123456')->setContactName('Awa')->setUnreadCount(7),
            collect([(new SmsMessageDomainObject)->setContent("Il reste des places ?\n<b>merci</b>")]),
        ))->locale('fr');

        $html = $mail->render();

        $mail->assertHasSubject('Nouveau SMS de Awa (+262639123456)');
        $this->assertStringContainsString('Il reste des places ?', $html);
        $this->assertStringContainsString('&lt;b&gt;merci&lt;/b&gt;', $html);
        $this->assertStringContainsString('7 messages non lus', $html);
        $this->assertStringContainsString('https://ticket.example.test/manage/organizer/4/sms', $html);
    }

    public function test_phone_offline_mail_renders_local_time_in_french(): void
    {
        $mail = (new SmsPhoneOfflineAlertMail(
            $this->organizer(),
            (new OrganizerSmsSettingDomainObject)
                ->setFromNumber('+262639000000')
                ->setPhoneStatusChangedAt('2026-10-21 09:30:00'),
        ))->locale('fr');

        $html = $mail->render();

        $mail->assertHasSubject('Votre téléphone SMS est hors ligne');
        $this->assertStringContainsString('+262639000000', $html);
        $this->assertStringContainsString('21/10/2026 12:30', $html);
        $this->assertStringContainsString('https://ticket.example.test/manage/organizer/4/settings#sms', $html);
    }

    private function organizer(): OrganizerDomainObject
    {
        return (new OrganizerDomainObject)
            ->setId(4)
            ->setName('Innocent Event')
            ->setEmail('orga@example.test')
            ->setTimezone('Indian/Mayotte');
    }
}
