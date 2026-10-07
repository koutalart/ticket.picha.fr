<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class OrderEmailsFormattingTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    /**
     * @return array{0: OrderDomainObject, 1: AttendeeDomainObject, 2: EventDomainObject, 3: EventSettingDomainObject, 4: OrganizerDomainObject}
     */
    private function purchase(): array
    {
        [$eventModel, $product, $productPrice] = $this->createEventWithProduct(price: 45.00);
        Event::whereKey($eventModel->id)->update([
            'title' => 'Triangle des Bermudes',
            'start_date' => '2026-10-11 15:00:00',
            'timezone' => 'Indian/Mayotte',
            'currency' => 'EUR',
        ]);
        EventSetting::where('event_id', $eventModel->id)->update([
            'location_details' => ['venue_name' => 'Le 5/5', 'address_line_1' => 'Rond point de la barge', 'city' => 'Mamoudzou', 'zip_or_postal_code' => '97600'],
            'support_email' => 'contact@innocent-event.test',
            'post_checkout_message' => '<p>Garde bien ton billet : il te sera demandé à l\'entrée.</p>',
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
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);

        $order = OrderDomainObject::hydrateFromModel($orderModel)->setOrderItems(new Collection([
            (new OrderItemDomainObject)
                ->setProductType('TICKET')
                ->setPrice(45)
                ->setProductPriceId($productPrice->id)
                ->setItemName('Entrée simple - 45 euros'),
        ]));

        return [
            $order,
            AttendeeDomainObject::hydrateFromModel($attendeeModel),
            EventDomainObject::hydrateFromModel(Event::findOrFail($eventModel->id)),
            EventSettingDomainObject::hydrateFromModel(EventSetting::where('event_id', $eventModel->id)->firstOrFail()),
            OrganizerDomainObject::hydrateFromModel(Organizer::findOrFail($eventModel->organizer_id)),
        ];
    }

    private function save(string $name, string $html): void
    {
        if ($directory = getenv('EMAIL_PREVIEW_DIR')) {
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    public function test_order_summary_is_written_the_french_way(): void
    {
        app()->setLocale('fr');
        [$order, , $event, $settings, $organizer] = $this->purchase();

        $html = (new OrderSummary($order, $event, $organizer, $settings, null))->render();
        $this->save('1-recapitulatif', $html);

        self::assertStringContainsString('dimanche 11 octobre 2026 à 18h00', $html);
        self::assertStringContainsString('45,99', $html);
        self::assertStringNotContainsString('€45.99', $html);
        self::assertStringNotContainsString('October', $html);
        self::assertStringContainsString('Le 5/5', $html);
        self::assertStringContainsString("Une question ? Répondez à cet e-mail ou contactez l'organisateur", html_entity_decode($html, ENT_QUOTES));
    }

    public function test_ticket_email_has_the_useful_details(): void
    {
        app()->setLocale('fr');
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase();

        $html = (new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer))->render();
        $this->save('2-billet', $html);
        $text = html_entity_decode($html, ENT_QUOTES);

        self::assertStringContainsString('Bonjour Client Exemple,', $text);
        self::assertStringContainsString('dimanche 11 octobre 2026 à 18h00', $text);
        self::assertStringContainsString('Entrée simple - 45 euros', $text);
        self::assertStringContainsString('Le 5/5', $text);
        self::assertStringNotContainsString("l'événement. à", $text);
    }

    public function test_single_buyer_email_lists_the_attached_ticket(): void
    {
        app()->setLocale('fr');
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase();

        $mail = new OrderSummary($order, $event, $organizer, $settings, null, null, [$attendee]);
        $html = $mail->render();
        $this->save('1-recapitulatif-avec-billet', $html);
        $text = html_entity_decode($html, ENT_QUOTES);

        self::assertStringContainsString('Votre billet', $text);
        self::assertStringContainsString('Votre billet est joint à cet e-mail (PDF)', $text);
        self::assertStringContainsString('Entrée simple - 45 euros', $text);
        self::assertStringContainsString('Client Exemple', $text);
    }
}
