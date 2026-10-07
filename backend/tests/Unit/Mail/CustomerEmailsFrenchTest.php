<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\EmailTemplateType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\Order\OrderCancelled;
use HiEvents\Mail\Order\OrderDetailsChangedMail;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Mail\Order\PaymentSuccessButOrderExpiredMail;
use HiEvents\Mail\Organizer\OrderSummaryForOrganizer;
use HiEvents\Mail\TicketLookup\TicketLookupEmail;
use HiEvents\Mail\Waitlist\WaitlistConfirmationMail;
use HiEvents\Mail\Waitlist\WaitlistOfferExpiredMail;
use HiEvents\Mail\Waitlist\WaitlistOfferMail;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\EmailTemplateService;
use HiEvents\Services\Domain\Email\EmailTokenContextBuilder;
use HiEvents\Values\MoneyValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Buyers receive every e-mail in French, whatever the language of their browser.
 */
class CustomerEmailsFrenchTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const ENGLISH_WORDS = '/\b(hello|thank you|best regards|your|you|please|order|tickets?|view|event|spot|waitlist|has been|offer|refund|amount|location)\b/i';

    /**
     * @return array{0: OrderDomainObject, 1: AttendeeDomainObject, 2: EventDomainObject, 3: EventSettingDomainObject, 4: OrganizerDomainObject}
     */
    private function purchase(string $buyerLocale = 'en'): array
    {
        [$eventModel, $product, $productPrice] = $this->createEventWithProduct(price: 45.00);
        Event::whereKey($eventModel->id)->update([
            'title' => 'Triangle des Bermudes',
            'start_date' => '2026-10-11 15:00:00',
            'timezone' => 'Indian/Mayotte',
            'currency' => 'EUR',
        ]);
        EventSetting::where('event_id', $eventModel->id)->update([
            'support_email' => 'contact@innocent-event.test',
            'notify_organizer_of_new_orders' => true,
        ]);
        Organizer::whereKey($eventModel->organizer_id)->update(['name' => 'Innocent Event', 'email' => 'contact@innocent-event.test']);

        $orderModel = Order::create([
            'event_id' => $eventModel->id,
            'total_gross' => 45.99,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'first_name' => 'Client',
            'last_name' => 'Exemple',
            'email' => 'client@exemple.test',
            'locale' => $buyerLocale,
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
            'first_name' => 'Client',
            'last_name' => 'Exemple',
            'locale' => $buyerLocale,
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

    private function visibleText(Mailable $mail): string
    {
        $html = preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $mail->render());
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES);
        $text = preg_replace('/[\w.+-]+@[\w.-]+/', '', $text);
        $text = str_replace(['Powered by PICHA AI', 'Triangle des Bermudes', 'Innocent Event', 'PICHA Ticket'], '', $text);

        return preg_replace('/\s+/u', ' ', str_replace(["\u{a0}", "\u{202f}"], ' ', $text));
    }

    public function test_every_customer_email_is_written_in_french(): void
    {
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase();
        app()->setLocale('en');

        $product = (new ProductDomainObject)->setId(1)->setTitle('Entrée simple');
        $entry = (new WaitlistEntryDomainObject)->setId(1)->setEventId($event->getId())->setEmail('client@exemple.test')
            ->setFirstName('Client')->setLocale('en')->setOfferExpiresAt('2026-10-10 15:00:00');
        $changed = ['E-mail' => ['old' => 'a@exemple.test', 'new' => 'b@exemple.test']];

        $mails = [
            'summary' => new OrderSummary($order, $event, $organizer, $settings, null, null, [$attendee]),
            'ticket' => new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer),
            'cancelled' => new OrderCancelled($order, $event, $organizer, $settings),
            'failed' => new OrderFailed($order, $event, $organizer, $settings),
            'refunded' => new OrderRefunded($order, $event, $organizer, $settings, MoneyValue::fromFloat(45, 'EUR')),
            'expired' => new PaymentSuccessButOrderExpiredMail($order, $event, $settings, $organizer),
            'order-changed' => new OrderDetailsChangedMail($event, $organizer, $settings, $changed),
            'attendee-changed' => new AttendeeDetailsChangedMail('Entrée simple', $event, $organizer, $settings, $changed),
            'lookup' => new TicketLookupEmail('client@exemple.test', 'token', 2),
            'waitlist-confirmation' => new WaitlistConfirmationMail($entry, $event, $product, null, $organizer, $settings),
            'waitlist-offer' => new WaitlistOfferMail($entry, $event, $product, null, $organizer, $settings, 'o_test', 'session'),
            'waitlist-expired' => new WaitlistOfferExpiredMail($entry, $event, $product, null, $organizer, $settings),
            'organizer' => new OrderSummaryForOrganizer($order, $event),
        ];

        foreach ($mails as $name => $mail) {
            $mail->locale('fr');
            if ($directory = getenv('EMAIL_PREVIEW_DIR')) {
                file_put_contents("$directory/$name.html", '<h2>'.e($mail->withLocale('fr', fn () => $mail->envelope()->subject)).'</h2>'.$mail->render());
            }
            $text = $this->visibleText($mail);
            $subject = $mail->withLocale('fr', fn () => $mail->envelope()->subject);

            self::assertDoesNotMatchRegularExpression(self::ENGLISH_WORDS, $text, "$name : texte anglais");
            self::assertDoesNotMatchRegularExpression(self::ENGLISH_WORDS, $subject, "$name : objet anglais");
            self::assertDoesNotMatchRegularExpression('/€\s?\d|EUR\s?\d|\d\.\d\d\b|\b(AM|PM)\b/', $text.' '.$subject, "$name : montant ou heure au format anglais");
            self::assertStringNotContainsString(',,', $text, "$name : ponctuation");
            self::assertDoesNotMatchRegularExpression('/[.!?],/', $text, "$name : virgule après un point");
            self::assertStringNotContainsString('  ', $subject, "$name : double espace dans l'objet");
            self::assertDoesNotMatchRegularExpression('/\\S[!?:;](\\s|$)/u', preg_replace('#https?://\\S+#', '', $text), "$name : espace manquante avant la ponctuation");
        }

        self::assertStringContainsString('45,00 €', $this->visibleText($mails['refunded']));
        self::assertStringContainsString('45,99 €', str_replace(["\u{a0}", "\u{202f}"], ' ', $mails['organizer']->withLocale('fr', fn () => $mails['organizer']->envelope()->subject)));
        self::assertStringContainsString('samedi 10 octobre 2026 à 18h00', $this->visibleText($mails['waitlist-offer']));
    }

    public function test_ticket_email_ignores_the_buyer_browser_language(): void
    {
        Mail::fake();
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase('en');

        app(SendAttendeeTicketService::class)->send($order, $attendee, $event, $settings, $organizer);

        Mail::assertQueued(AttendeeTicketMail::class, fn (AttendeeTicketMail $mail) => $mail->locale === 'fr');
    }

    public function test_default_custom_templates_and_preview_are_in_french(): void
    {
        app()->setLocale('en');

        $template = app(EmailTemplateService::class)->getDefaultTemplate(EmailTemplateType::ORDER_CONFIRMATION);
        self::assertStringContainsString('Votre commande est confirmée', $template['subject']);
        self::assertStringContainsString('Récapitulatif de la commande', $template['body']);

        $preview = app(EmailTokenContextBuilder::class)->buildPreviewContext('attendee_ticket');
        self::assertSame('150,00 €', str_replace(["\u{a0}", "\u{202f}"], ' ', $preview['order']['total']));
        self::assertStringContainsString('2029', $preview['event']['date']);
        self::assertDoesNotMatchRegularExpression('/April|PM|\$/', $preview['event']['date'].$preview['event']['time'].$preview['ticket']['price']);
    }
}
