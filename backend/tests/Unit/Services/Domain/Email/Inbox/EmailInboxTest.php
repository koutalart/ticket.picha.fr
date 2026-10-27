<?php

namespace Tests\Unit\Services\Domain\Email\Inbox;

use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\DomainObjects\Status\EmailThreadStatus;
use HiEvents\Exceptions\EmailRecipientNotAllowedException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\IdHelper;
use HiEvents\Mail\Event\EventMessage;
use HiEvents\Models\EmailMessage;
use HiEvents\Models\EmailThread;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Email\Inbox\DTO\ComposeEmailDTO;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailThreadSearchDTO;
use HiEvents\Services\Domain\Email\Inbox\EmailComposerService;
use HiEvents\Services\Domain\Email\Inbox\EmailDeliveryStatusService;
use HiEvents\Services\Domain\Email\Inbox\EmailReplyAddressService;
use HiEvents\Services\Domain\Email\Inbox\InboundEmailService;
use HiEvents\Services\Domain\Email\Inbox\OutgoingMessageImportService;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\EmailProviderEventDTO;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\InboundEmailDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class EmailInboxTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const BUYER = 'oyhana@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'mail.from.address' => 'ticket@picha.fr',
            'services.email_inbox.reply_domain' => 'in.picha.fr',
            'services.email_inbox.track_transactional_replies' => false,
        ]);
    }

    public function test_a_message_to_a_buyer_is_sent_logged_and_answerable_through_its_thread_address(): void
    {
        [$event, , , $user] = $this->createEventWithProduct();
        $this->order($event, 'Oyhana@Example.test');

        $message = $this->composer()->send(new ComposeEmailDTO(
            organizerId: $event->organizer_id,
            userId: $user->id,
            body: "Bonjour,\n<b>les portes ouvrent à 20h</b>",
            subject: 'Infos pratiques',
            email: 'OYHANA@example.test',
        ));

        $this->assertSame(EmailMessageStatus::SENT->name, $message->getStatus());
        $this->assertNotNull($message->getRfcMessageId());
        $this->assertStringContainsString('&lt;b&gt;', (string) $message->getBodyHtml());

        $thread = EmailThread::findOrFail($message->getEmailThreadId());
        $this->assertSame(self::BUYER, $thread->email);
        $this->assertSame('Oyhana Inoussa', $thread->contact_name);
        $this->assertSame('Infos pratiques', $thread->last_message_subject);

        $sent = $this->sentEmails()->last();
        $this->assertSame([self::BUYER], array_map(fn (Address $a) => $a->getAddress(), $sent->getTo()));
        $this->assertSame(
            [$this->replyAddresses()->addressFor($thread->id, $event->organizer_id)],
            array_map(fn (Address $a) => $a->getAddress(), $sent->getReplyTo()),
        );
    }

    public function test_an_address_that_is_not_a_contact_of_the_organizer_is_refused(): void
    {
        [$event, , , $user] = $this->createEventWithProduct();
        [$otherEvent] = $this->createEventWithProduct();
        $this->order($otherEvent, 'someone-else@example.test');

        $this->expectException(EmailRecipientNotAllowedException::class);

        $this->composer()->send(new ComposeEmailDTO(
            organizerId: $event->organizer_id,
            userId: $user->id,
            body: 'Hello',
            subject: 'Hello',
            email: 'someone-else@example.test',
        ));
    }

    public function test_an_organizer_cannot_reply_in_the_thread_of_another_organizer_of_the_same_account(): void
    {
        [$event, , , $user] = $this->createEventWithProduct();
        [$siblingEvent] = $this->createEventWithProductOnAccount($event->account_id);
        $this->order($event, self::BUYER);

        $message = $this->composer()->send(new ComposeEmailDTO(
            organizerId: $event->organizer_id,
            userId: $user->id,
            body: 'Hello',
            subject: 'Hello',
            email: self::BUYER,
        ));

        $search = $this->app->make(EmailThreadRepositoryInterface::class);
        $this->assertSame(0, $search->searchForOrganizer($siblingEvent->organizer_id, new EmailThreadSearchDTO)->total());
        $this->assertCount(0, $search->searchRecipients($siblingEvent->organizer_id, 'oyhana', 10));

        $this->expectException(ResourceNotFoundException::class);

        $this->composer()->send(new ComposeEmailDTO(
            organizerId: $siblingEvent->organizer_id,
            userId: $user->id,
            body: 'Hello',
            threadId: $message->getEmailThreadId(),
        ));
    }

    public function test_a_reply_lands_in_its_thread_sanitized_and_marks_it_to_handle_once(): void
    {
        [$event, , , $user] = $this->createEventWithProduct();
        $this->order($event, self::BUYER);
        $sent = $this->composer()->send(new ComposeEmailDTO(
            organizerId: $event->organizer_id,
            userId: $user->id,
            body: 'Hello',
            subject: 'Infos',
            email: self::BUYER,
        ));
        $address = $this->replyAddresses()->addressFor($sent->getEmailThreadId(), $event->organizer_id);

        $reply = $this->inbound($address, '<p>Merci !</p><script>alert(1)</script><img src="x" onerror="alert(2)">', "Merci !\n\nLe lun. 27 oct. 2026, PICHA a écrit :\n> Hello");

        $first = $this->inboundService()->handle($reply, 'RESEND');
        $again = $this->inboundService()->handle($reply, 'RESEND');

        $this->assertSame($first->getId(), $again->getId());
        $this->assertSame($sent->getEmailThreadId(), $first->getEmailThreadId());
        $this->assertStringNotContainsString('script', (string) $first->getBodyHtml());
        $this->assertStringNotContainsString('onerror', (string) $first->getBodyHtml());
        $this->assertSame('Merci !', $first->getPreview());

        $thread = EmailThread::findOrFail($sent->getEmailThreadId());
        $this->assertSame(EmailThreadStatus::NEEDS_HUMAN->name, $thread->status);
        $this->assertSame(1, $thread->unread_count);
        $this->assertSame('INBOUND', $thread->last_message_direction);

        $answer = $this->composer()->send(new ComposeEmailDTO(
            organizerId: $event->organizer_id,
            userId: $user->id,
            body: 'Avec plaisir',
            threadId: $sent->getEmailThreadId(),
        ));

        $this->assertSame('Re: Infos', $answer->getSubject());
        $this->assertSame('<reply-1@example.test>', $answer->getInReplyTo());
        $this->assertSame(EmailThreadStatus::OPEN->name, EmailThread::findOrFail($sent->getEmailThreadId())->status);
        $this->assertSame('<reply-1@example.test>', $this->sentEmails()->last()->getHeaders()->get('In-Reply-To')?->getBodyAsString());
    }

    public function test_a_reply_with_a_tampered_or_foreign_address_is_ignored(): void
    {
        [$event, , , $user] = $this->createEventWithProduct();
        [$siblingEvent] = $this->createEventWithProductOnAccount($event->account_id);
        $this->order($event, self::BUYER);
        $sent = $this->composer()->send(new ComposeEmailDTO(
            organizerId: $event->organizer_id,
            userId: $user->id,
            body: 'Hello',
            subject: 'Infos',
            email: self::BUYER,
        ));

        $signedForSibling = $this->replyAddresses()->addressFor($sent->getEmailThreadId(), $siblingEvent->organizer_id);
        $tampered = preg_replace('/-[0-9a-f]{20}@/', '-'.str_repeat('0', 20).'@', $this->replyAddresses()->addressFor($sent->getEmailThreadId(), $event->organizer_id));

        $this->assertNull($this->inboundService()->handle($this->inbound($signedForSibling, '<p>x</p>', 'x', 'in_a'), 'RESEND'));
        $this->assertNull($this->inboundService()->handle($this->inbound($tampered, '<p>x</p>', 'x', 'in_b'), 'RESEND'));
        $this->assertSame(0, EmailMessage::where('direction', 'INBOUND')->where('organizer_id', $event->organizer_id)->count());
    }

    public function test_an_event_message_to_a_customer_is_logged_and_the_copy_to_the_team_is_not(): void
    {
        [$event, , , $user] = $this->createEventWithProduct();
        config(['services.email_inbox.track_transactional_replies' => true]);
        DB::table('event_settings')->where('event_id', $event->id)->update(['support_email' => 'support@organizer.test']);

        $mailable = fn () => new EventMessage(
            event: $this->eventDomainObject($event->id),
            eventSettings: $this->eventDomainObject($event->id)->getEventSettings(),
            messageData: new SendMessageDTO(
                account_id: $event->account_id,
                event_id: $event->id,
                subject: 'Ouverture des portes',
                message: '<p>Les portes ouvrent à 20h.</p>',
                type: MessageTypeEnum::ALL_ATTENDEES,
                is_test: false,
                send_copy_to_current_user: false,
                sent_by_user_id: $user->id,
            ),
        );

        Mail::to(self::BUYER)->sendNow($mailable());
        Mail::to($user->email)->sendNow($mailable());

        $logged = EmailMessage::where('organizer_id', $event->organizer_id)->get();
        $this->assertCount(1, $logged);
        $this->assertSame('EventMessage', $logged[0]->source);
        $this->assertSame($event->id, $logged[0]->event_id);
        $this->assertSame('Ouverture des portes', $logged[0]->subject);
        $this->assertStringContainsString('Les portes ouvrent à 20h.', (string) $logged[0]->preview);

        $replyTo = array_map(fn (Address $a) => $a->getAddress(), $this->sentEmails()->first()->getReplyTo());
        $this->assertSame([
            $this->replyAddresses()->addressFor($logged[0]->email_thread_id, $event->organizer_id),
            'support@organizer.test',
        ], $replyTo);
    }

    public function test_delivery_statuses_only_move_forward_but_a_bounce_always_wins(): void
    {
        [$event] = $this->createEventWithProduct();
        $message = $this->loggedMessage($event, 'em_'.Str::random(8));
        $status = $this->app->make(EmailDeliveryStatusService::class);

        $status->apply($this->statusEvent($message->provider_message_id, EmailMessageStatus::OPENED));
        $status->apply($this->statusEvent($message->provider_message_id, EmailMessageStatus::DELIVERED));
        $message->refresh();
        $this->assertSame('OPENED', $message->status);
        $this->assertNotNull($message->opened_at);
        $this->assertNotNull($message->delivered_at);

        $status->apply(new EmailProviderEventDTO(
            kind: EmailProviderEventDTO::KIND_STATUS,
            providerEmailId: $message->provider_message_id,
            status: EmailMessageStatus::COMPLAINED,
            occurredAt: now()->toIso8601String(),
            errorMessage: 'Marked as spam',
        ));

        $message->refresh();
        $this->assertSame('COMPLAINED', $message->status);
        $this->assertSame('Marked as spam', $message->error_message);
        $this->assertSame('COMPLAINED', EmailThread::findOrFail($message->email_thread_id)->last_message_status);

        $this->assertNull($status->apply($this->statusEvent('unknown-id', EmailMessageStatus::DELIVERED)));
    }

    public function test_past_event_messages_are_imported_once_without_the_team_copies(): void
    {
        [$event, , , $user] = $this->createEventWithProduct();
        $messageId = DB::table('messages')->insertGetId([
            'event_id' => $event->id,
            'subject' => 'Rappel',
            'message' => '<p>On vous attend <b>samedi</b>.</p>',
            'type' => 'ALL_ATTENDEES',
            'sent_by_user_id' => $user->id,
            'status' => 'SENT',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);
        foreach ([self::BUYER, $user->email] as $recipient) {
            DB::table('outgoing_messages')->insert([
                'event_id' => $event->id,
                'message_id' => $messageId,
                'subject' => 'Rappel',
                'recipient' => $recipient,
                'status' => 'SENT',
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ]);
        }

        $import = $this->app->make(OutgoingMessageImportService::class);
        $first = $import->import($event->organizer_id);
        $second = $import->import($event->organizer_id);

        $this->assertSame(1, $first->imported);
        $this->assertSame(0, $second->imported);

        $logged = EmailMessage::where('organizer_id', $event->organizer_id)->get();
        $this->assertCount(1, $logged);
        $this->assertSame('On vous attend samedi.', $logged[0]->preview);
        $this->assertSame(now()->subDays(10)->toDateString(), substr((string) $logged[0]->created_at, 0, 10));
    }

    private function composer(): EmailComposerService
    {
        return $this->app->make(EmailComposerService::class);
    }

    private function inboundService(): InboundEmailService
    {
        return $this->app->make(InboundEmailService::class);
    }

    private function replyAddresses(): EmailReplyAddressService
    {
        return $this->app->make(EmailReplyAddressService::class);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Email>
     */
    private function sentEmails()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $message) => $message->getOriginalMessage());
    }

    private function inbound(string $to, string $html, string $text, string $id = 'in_1'): InboundEmailDTO
    {
        return new InboundEmailDTO(
            providerEmailId: $id,
            fromEmail: self::BUYER,
            fromName: 'Oyhana',
            recipients: [$to],
            subject: 'Re: Infos',
            text: $text,
            html: $html,
            messageId: '<reply-1@example.test>',
            references: [],
            attachments: [],
            receivedAt: now()->toIso8601String(),
        );
    }

    private function statusEvent(string $providerId, EmailMessageStatus $status): EmailProviderEventDTO
    {
        return new EmailProviderEventDTO(
            kind: EmailProviderEventDTO::KIND_STATUS,
            providerEmailId: $providerId,
            status: $status,
            occurredAt: now()->toIso8601String(),
        );
    }

    private function loggedMessage(Event $event, string $providerId): EmailMessage
    {
        $thread = EmailThread::create([
            'account_id' => $event->account_id,
            'organizer_id' => $event->organizer_id,
            'email' => self::BUYER,
            'status' => 'OPEN',
            'last_message_at' => now()->subMinute(),
            'last_message_direction' => 'OUTBOUND',
            'last_message_status' => 'SENT',
        ]);

        return EmailMessage::create([
            'email_thread_id' => $thread->id,
            'organizer_id' => $event->organizer_id,
            'direction' => 'OUTBOUND',
            'source' => 'OrderSummary',
            'to_email' => self::BUYER,
            'status' => 'SENT',
            'provider' => 'RESEND',
            'provider_message_id' => $providerId,
        ]);
    }

    private function eventDomainObject(int $eventId): EventDomainObject
    {
        return $this->app->make(EventRepositoryInterface::class)
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($eventId);
    }

    private function order(Event $event, string $email): Order
    {
        return Order::create([
            'event_id' => $event->id,
            'total_gross' => 25,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'first_name' => 'Oyhana',
            'last_name' => 'Inoussa',
            'email' => $email,
            'locale' => 'fr',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
    }
}
