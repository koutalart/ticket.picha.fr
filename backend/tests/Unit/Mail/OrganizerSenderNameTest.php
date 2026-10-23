<?php

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\BaseMail;
use HiEvents\Mail\Event\EventMessage;
use HiEvents\Mail\Order\OrderCancelled;
use HiEvents\Mail\Order\OrderDetailsChangedMail;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Mail\Order\PaymentSuccessButOrderExpiredMail;
use HiEvents\Mail\Waitlist\WaitlistConfirmationMail;
use HiEvents\Mail\Waitlist\WaitlistOfferExpiredMail;
use HiEvents\Mail\Waitlist\WaitlistOfferMail;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Values\MoneyValue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class OrganizerSenderNameTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORGANIZER_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'ticket@picha.fr', 'mail.from.name' => 'PICHA Ticket']);
    }

    private function useSenderName(?string $senderName): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository
            ->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => self::ORGANIZER_ID])
            ->andReturn((new OrganizerSettingDomainObject)->setOrganizerId(self::ORGANIZER_ID)->setEmailSenderName($senderName));

        $this->app->instance(OrganizerSettingsRepositoryInterface::class, $repository);
    }

    /**
     * @return array<string, BaseMail>
     */
    private function organizerMails(): array
    {
        $event = (new EventDomainObject)
            ->setId(5)
            ->setOrganizerId(self::ORGANIZER_ID)
            ->setTitle('Triangle des Bermudes')
            ->setTimezone('Indian/Mayotte')
            ->setCurrency('EUR');
        $settings = (new EventSettingDomainObject)->setEventId(5)->setSupportEmail('contact@innocent-event.test');
        $organizer = (new OrganizerDomainObject)->setId(self::ORGANIZER_ID)->setName('Innocent Event');
        $order = (new OrderDomainObject)->setId(9)->setEventId(5)->setShortId('o_test')->setCurrency('EUR');
        $attendee = (new AttendeeDomainObject)->setId(3)->setOrderId(9)->setShortId('a_test');
        $product = (new ProductDomainObject)->setId(1)->setTitle('Entrée simple');
        $entry = (new WaitlistEntryDomainObject)->setId(1)->setEventId(5)->setEmail('client@exemple.test');
        $changed = ['E-mail' => ['old' => 'a@exemple.test', 'new' => 'b@exemple.test']];
        $message = new SendMessageDTO(
            account_id: 1,
            event_id: 5,
            subject: 'Infos pratiques',
            message: 'Bonjour',
            type: MessageTypeEnum::ORDER_OWNER,
            is_test: false,
            send_copy_to_current_user: false,
            sent_by_user_id: 1,
        );

        return [
            'OrderSummary' => new OrderSummary($order, $event, $organizer, $settings, null),
            'AttendeeTicketMail' => new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer),
            'EventMessage' => new EventMessage($event, $settings, $message),
            'OrderCancelled' => new OrderCancelled($order, $event, $organizer, $settings),
            'OrderFailed' => new OrderFailed($order, $event, $organizer, $settings),
            'OrderRefunded' => new OrderRefunded($order, $event, $organizer, $settings, MoneyValue::fromFloat(45, 'EUR')),
            'PaymentSuccessButOrderExpiredMail' => new PaymentSuccessButOrderExpiredMail($order, $event, $settings, $organizer),
            'OrderDetailsChangedMail' => new OrderDetailsChangedMail($event, $organizer, $settings, $changed),
            'AttendeeDetailsChangedMail' => new AttendeeDetailsChangedMail('Entrée simple', $event, $organizer, $settings, $changed),
            'WaitlistConfirmationMail' => new WaitlistConfirmationMail($entry, $event, $product, null, $organizer, $settings),
            'WaitlistOfferMail' => new WaitlistOfferMail($entry, $event, $product, null, $organizer, $settings, 'o_test', 'session'),
            'WaitlistOfferExpiredMail' => new WaitlistOfferExpiredMail($entry, $event, $product, null, $organizer, $settings),
        ];
    }

    public function test_every_organizer_email_uses_the_sender_name_with_the_platform_address(): void
    {
        $this->useSenderName('Innocent Event');

        foreach ($this->organizerMails() as $name => $mail) {
            $envelope = $mail->envelope();

            $this->assertNotNull($envelope->from, "$name : expéditeur absent");
            $this->assertSame('Innocent Event', $envelope->from->name, "$name : nom de l'expéditeur");
            $this->assertSame('ticket@picha.fr', $envelope->from->address, "$name : adresse d'envoi");
            $this->assertSame('contact@innocent-event.test', $envelope->replyTo[0]->address, "$name : répondre à");
            $this->assertTrue($mail->hasFrom('ticket@picha.fr', 'Innocent Event'), "$name : en-tête From");
        }
    }

    public function test_empty_sender_name_keeps_the_platform_default(): void
    {
        $this->useSenderName(null);

        foreach ($this->organizerMails() as $name => $mail) {
            $envelope = $mail->envelope();

            $this->assertNull($envelope->from, "$name : l'expéditeur global doit rester celui de la plateforme");
            $this->assertSame('contact@innocent-event.test', $envelope->replyTo[0]->address, "$name : répondre à");
        }
    }

    public function test_sender_name_is_sanitized_in_the_envelope(): void
    {
        $this->useSenderName("Innocent\r\nBcc: victime@exemple.test");

        foreach ($this->organizerMails() as $name => $mail) {
            $from = $mail->envelope()->from;

            $this->assertSame('Innocent Bcc: victime@exemple.test', $from->name, $name);
            $this->assertSame('ticket@picha.fr', $from->address, $name);
        }
    }
}
