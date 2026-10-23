<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\EmailTemplateType;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\IdHelper;
use HiEvents\Mail\Attendee\AttendanceAccessCodeMail;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
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
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Email\EmailTemplateService;
use HiEvents\Values\MoneyValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Organizers who chose « tu » send every customer e-mail in « tu »; the others keep « vous ».
 */
class CustomerEmailsTutoiementTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const VOUS = '/\b(vous|votre|vos)\b/iu';

    /**
     * @return array{0: OrderDomainObject, 1: AttendeeDomainObject, 2: EventDomainObject, 3: EventSettingDomainObject, 4: OrganizerDomainObject}
     */
    private function purchase(string $addressForm): array
    {
        [$eventModel, $product, $productPrice] = $this->createEventWithProduct(price: 45.00);
        Event::whereKey($eventModel->id)->update([
            'title' => 'Triangle des Bermudes',
            'start_date' => '2026-10-31 15:00:00',
            'timezone' => 'Indian/Mayotte',
            'currency' => 'EUR',
        ]);
        EventSetting::where('event_id', $eventModel->id)->update(['support_email' => 'contact@innocent-event.test']);
        Organizer::whereKey($eventModel->organizer_id)->update(['name' => 'Innocent Event', 'email' => 'contact@innocent-event.test']);
        DB::table('organizer_settings')->updateOrInsert(
            ['organizer_id' => $eventModel->organizer_id],
            ['customer_address_form' => $addressForm],
        );

        $orderModel = Order::create([
            'event_id' => $eventModel->id,
            'total_gross' => 45.99,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'first_name' => 'Awa',
            'last_name' => 'Exemple',
            'email' => 'client@exemple.test',
            'locale' => 'fr',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => 'O-EXEMPLE',
        ]);
        $attendeeModel = Attendee::create([
            'event_id' => $eventModel->id,
            'order_id' => $orderModel->id,
            'product_id' => $product->id,
            'product_price_id' => $productPrice->id,
            'status' => 'ACTIVE',
            'email' => 'client@exemple.test',
            'first_name' => 'Awa',
            'last_name' => 'Exemple',
            'locale' => 'fr',
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);

        $order = OrderDomainObject::hydrateFromModel($orderModel)->setOrderItems(new Collection([
            (new OrderItemDomainObject)
                ->setProductType('TICKET')
                ->setPrice(45)
                ->setProductPriceId($productPrice->id)
                ->setItemName('Entrée simple'),
        ]));

        return [
            $order,
            AttendeeDomainObject::hydrateFromModel($attendeeModel),
            EventDomainObject::hydrateFromModel(Event::findOrFail($eventModel->id)),
            EventSettingDomainObject::hydrateFromModel(EventSetting::where('event_id', $eventModel->id)->firstOrFail()),
            OrganizerDomainObject::hydrateFromModel(Organizer::findOrFail($eventModel->organizer_id)),
        ];
    }

    /**
     * @return array<string, Mailable>
     */
    private function customerMails(OrderDomainObject $order, AttendeeDomainObject $attendee, EventDomainObject $event, EventSettingDomainObject $settings, OrganizerDomainObject $organizer): array
    {
        $product = (new ProductDomainObject)->setId(1)->setTitle('Entrée simple');
        $entry = (new WaitlistEntryDomainObject)->setId(1)->setEventId($event->getId())->setEmail('client@exemple.test')
            ->setFirstName('Awa')->setLocale('fr')->setOfferExpiresAt('2026-10-30 15:00:00');
        $changed = ['E-mail' => ['old' => 'a@exemple.test', 'new' => 'b@exemple.test']];
        $message = new SendMessageDTO(
            account_id: $event->getAccountId(),
            event_id: $event->getId(),
            subject: 'Infos pratiques',
            message: '<p>Les portes ouvrent à 18h.</p>',
            type: MessageTypeEnum::ORDER_OWNER,
            is_test: false,
            send_copy_to_current_user: false,
            sent_by_user_id: 1,
        );

        return [
            'order-cancelled' => new OrderCancelled($order, $event, $organizer, $settings),
            'order-failed' => new OrderFailed($order, $event, $organizer, $settings),
            'order-refunded' => new OrderRefunded($order, $event, $organizer, $settings, MoneyValue::fromFloat(45, 'EUR')),
            'payment-received-after-expiry' => new PaymentSuccessButOrderExpiredMail($order, $event, $settings, $organizer),
            'order-modified' => new OrderDetailsChangedMail($event, $organizer, $settings, $changed),
            'ticket-modified' => new AttendeeDetailsChangedMail('Entrée simple', $event, $organizer, $settings, $changed),
            'attendance-access-code' => new AttendanceAccessCodeMail('Triangle des Bermudes', '482913', 15, $event),
            'waitlist-confirmation' => new WaitlistConfirmationMail($entry, $event, $product, null, $organizer, $settings),
            'waitlist-offer' => new WaitlistOfferMail($entry, $event, $product, null, $organizer, $settings, 'o_test', 'session'),
            'waitlist-offer-expired' => new WaitlistOfferExpiredMail($entry, $event, $product, null, $organizer, $settings),
            'organizer-message' => new EventMessage($event, $settings, $message),
            'order-confirmation-default' => new OrderSummary($order, $event, $organizer, $settings, null, null, [$attendee]),
            'ticket-default' => new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer, null, [$attendee, $attendee]),
        ];
    }

    private function visibleText(Mailable $mail): string
    {
        $html = preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $mail->render());
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES);
        $text = str_replace('Rendez-vous', '', $text);

        return preg_replace('/\s+/u', ' ', str_replace(["\u{a0}", "\u{202f}"], ' ', $text));
    }

    private function subject(Mailable $mail): string
    {
        return $mail->withLocale($mail->locale, fn () => $mail->envelope()->subject);
    }

    public function test_every_customer_email_says_tu_when_the_organizer_chose_it(): void
    {
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase('tu');
        $locale = app(CustomerLocaleResolver::class)->forEvent($event);
        self::assertSame(CustomerLocale::FRENCH_TUTOIEMENT, $locale);

        $directory = getenv('TU_EMAIL_SAMPLES_DIR') ?: null;

        foreach ($this->customerMails($order, $attendee, $event, $settings, $organizer) as $name => $mail) {
            $mail->locale($locale);
            $text = $this->visibleText($mail);
            $subject = $this->subject($mail);

            if ($directory !== null) {
                file_put_contents("$directory/$name.html", '<h2>'.e($subject).'</h2>'.$mail->render());
            }

            self::assertDoesNotMatchRegularExpression(self::VOUS, $text, "$name : « vous » dans le texte");
            self::assertDoesNotMatchRegularExpression(self::VOUS, $subject, "$name : « vous » dans l'objet");
        }

        $mails = $this->customerMails($order, $attendee, $event, $settings, $organizer);
        array_walk($mails, fn (Mailable $mail) => $mail->locale($locale));

        self::assertSame('Ta commande a été annulée', $this->subject($mails['order-cancelled']));
        self::assertStringContainsString('Une question ou besoin d\'aide ? Réponds simplement à cet e-mail.', $this->visibleText($mails['order-cancelled']));
        self::assertStringContainsString('Tu as reçu un remboursement de 45,00 €', $this->visibleText($mails['order-refunded']));
        self::assertStringContainsString('On te prévient dès qu\'une place se libère.', $this->visibleText($mails['waitlist-confirmation']));
        self::assertStringContainsString('samedi 31 octobre 2026', $this->visibleText($mails['order-confirmation-default']));
        self::assertStringContainsString('Tes billets sont joints à cet e-mail (PDF)', $this->visibleText($mails['ticket-default']));
        self::assertStringContainsString('Tes billets (2)', $this->visibleText($mails['ticket-default']));
        self::assertStringContainsString('Tu reçois ce message parce que tu es inscrit(e)', $this->visibleText($mails['organizer-message']));
        self::assertStringContainsString('Saisis-le sur la page de l\'événement', $this->visibleText($mails['attendance-access-code']));
    }

    public function test_organizers_in_vous_keep_their_emails_unchanged(): void
    {
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase('vous');
        $locale = app(CustomerLocaleResolver::class)->forEvent($event);
        self::assertSame('fr', $locale);

        $mail = (new OrderCancelled($order, $event, $organizer, $settings))->locale($locale);

        self::assertSame('Votre commande a été annulée', $this->subject($mail));
        self::assertStringContainsString('Votre commande de', $this->visibleText($mail));
    }

    public function test_the_ticket_email_is_queued_in_tu_for_an_organizer_in_tu(): void
    {
        Mail::fake();
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase('tu');

        app(SendAttendeeTicketService::class)->send($order, $attendee, $event, $settings, $organizer);

        Mail::assertQueued(AttendeeTicketMail::class, fn (AttendeeTicketMail $mail) => $mail->locale === CustomerLocale::FRENCH_TUTOIEMENT);
    }

    public function test_default_templates_in_the_editor_follow_the_address_form(): void
    {
        $service = app(EmailTemplateService::class);

        $vous = $service->getDefaultTemplate(EmailTemplateType::ORDER_CONFIRMATION);
        $tu = $service->getDefaultTemplate(EmailTemplateType::ORDER_CONFIRMATION, CustomerAddressForm::TU);
        $ticket = $service->getDefaultTemplate(EmailTemplateType::ATTENDEE_TICKET, CustomerAddressForm::TU);

        self::assertSame('Votre commande est confirmée ! 🎉', $vous['subject']);
        self::assertSame('Ta commande est confirmée ! 🎉', $tu['subject']);
        self::assertSame('🎟️ Ton billet pour {{ event.title }}', $ticket['subject']);
        self::assertStringContainsString('Au plaisir de t\'accueillir !', $tu['body']);
        self::assertStringContainsString('présente ton billet', $ticket['body']);

        foreach ([$tu, $ticket] as $template) {
            self::assertDoesNotMatchRegularExpression(self::VOUS, $template['subject'].$template['body']);
            self::assertDoesNotMatchRegularExpression('/[•·—–]/u', $template['subject'].$template['body']);
            self::assertSame([], array_values(array_filter(
                [$template['cta']['label'] ?? ''],
                fn (string $label) => preg_match(self::VOUS, $label) === 1,
            )));
        }
    }
}
