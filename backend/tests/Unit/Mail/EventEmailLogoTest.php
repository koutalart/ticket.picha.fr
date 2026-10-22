<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
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
use HiEvents\Models\Image;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Values\MoneyValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Every e-mail sent to an event's participants shows, in order: the event e-mail logo,
 * the organizer e-mail logo, the organizer logo, and the platform logo as a last resort.
 */
class EventEmailLogoTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const EVENT_LOGO = 'event_email_logo/kulturm-test.png';

    private const ORGANIZER_EMAIL_LOGO = 'organizer_email_logo/m1-fond-clair-test.png';

    private const ORGANIZER_LOGO = 'organizer_logo/m1-blanc-test.png';

    private const PLATFORM_LOGO = 'picha-email-logo.png';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.cdn_url_override' => 'https://cdn.test', 'app.email_logo_url' => null]);
    }

    /**
     * @return array{0: OrderDomainObject, 1: AttendeeDomainObject, 2: EventDomainObject, 3: EventSettingDomainObject, 4: OrganizerDomainObject}
     */
    private function purchase(float $price = 0): array
    {
        [$eventModel, $product, $productPrice] = $this->createEventWithProduct(price: $price);

        $orderModel = Order::create([
            'event_id' => $eventModel->id,
            'total_gross' => $price,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'first_name' => 'Client',
            'last_name' => 'Exemple',
            'email' => 'client@exemple.test',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
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
            (new OrderItemDomainObject)->setProductType('TICKET')->setPrice($price)->setProductPriceId($productPrice->id)->setItemName('Entrée'),
        ]));

        return [
            $order,
            AttendeeDomainObject::hydrateFromModel($attendeeModel),
            EventDomainObject::hydrateFromModel(Event::findOrFail($eventModel->id)),
            EventSettingDomainObject::hydrateFromModel(EventSetting::where('event_id', $eventModel->id)->firstOrFail()),
            OrganizerDomainObject::hydrateFromModel(Organizer::findOrFail($eventModel->organizer_id)),
        ];
    }

    private function addImage(int $entityId, string $entityType, string $type, string $path, int $accountId): void
    {
        Image::create([
            'account_id' => $accountId,
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'type' => $type,
            'filename' => basename($path),
            'disk' => 'public',
            'path' => $path,
            'size' => 1000,
            'mime_type' => 'image/png',
        ]);
    }

    /**
     * @return array<string, Mailable>
     */
    private function participantMails(OrderDomainObject $order, AttendeeDomainObject $attendee, EventDomainObject $event, EventSettingDomainObject $settings, OrganizerDomainObject $organizer): array
    {
        $product = (new ProductDomainObject)->setId(1)->setTitle('Entrée');
        $entry = (new WaitlistEntryDomainObject)->setId(1)->setEventId($event->getId())->setEmail('client@exemple.test')
            ->setFirstName('Client')->setLocale('fr')->setOfferExpiresAt('2026-10-10 15:00:00');
        $changed = ['E-mail' => ['old' => 'a@exemple.test', 'new' => 'b@exemple.test']];
        $message = new SendMessageDTO(
            account_id: $event->getAccountId(),
            event_id: $event->getId(),
            subject: 'Infos pratiques',
            message: '<p>Rendez-vous à 18h.</p>',
            type: MessageTypeEnum::ORDER_OWNER,
            is_test: false,
            send_copy_to_current_user: false,
            sent_by_user_id: 1,
        );

        return [
            'summary' => new OrderSummary($order, $event, $organizer, $settings, null),
            'ticket' => new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer),
            'cancelled' => new OrderCancelled($order, $event, $organizer, $settings),
            'failed' => new OrderFailed($order, $event, $organizer, $settings),
            'refunded' => new OrderRefunded($order, $event, $organizer, $settings, MoneyValue::fromFloat(10, 'EUR')),
            'expired' => new PaymentSuccessButOrderExpiredMail($order, $event, $settings, $organizer),
            'order-changed' => new OrderDetailsChangedMail($event, $organizer, $settings, $changed),
            'attendee-changed' => new AttendeeDetailsChangedMail('Entrée', $event, $organizer, $settings, $changed),
            'attendance-code' => new AttendanceAccessCodeMail($event->getTitle(), '123456', 10, $event),
            'message' => new EventMessage($event, $settings, $message),
            'waitlist-confirmation' => new WaitlistConfirmationMail($entry, $event, $product, null, $organizer, $settings),
            'waitlist-offer' => new WaitlistOfferMail($entry, $event, $product, null, $organizer, $settings, 'o_test', 'session'),
            'waitlist-expired' => new WaitlistOfferExpiredMail($entry, $event, $product, null, $organizer, $settings),
        ];
    }

    private function headerLogo(Mailable $mail): string
    {
        $html = $mail->render();
        preg_match('#class="header"[^>]*>.*?<img[^>]*\ssrc="([^"]+)"#s', $html, $matches);

        return $matches[1] ?? '';
    }

    public function test_event_email_logo_wins_in_every_participant_email(): void
    {
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase(price: 25);
        $this->addImage($event->getId(), EventDomainObject::class, 'EVENT_EMAIL_LOGO', self::EVENT_LOGO, $event->getAccountId());
        $this->addImage($organizer->getId(), OrganizerDomainObject::class, 'ORGANIZER_EMAIL_LOGO', self::ORGANIZER_EMAIL_LOGO, $event->getAccountId());
        $this->addImage($organizer->getId(), OrganizerDomainObject::class, 'ORGANIZER_LOGO', self::ORGANIZER_LOGO, $event->getAccountId());

        foreach ($this->participantMails($order, $attendee, $event, $settings, $organizer) as $name => $mail) {
            self::assertSame('https://cdn.test/'.self::EVENT_LOGO, $this->headerLogo($mail), "$name : logo de l'événement attendu");
        }
    }

    public function test_cancellation_email_uses_the_organizer_email_logo(): void
    {
        [$order, , $event, $settings, $organizer] = $this->purchase();
        $this->addImage($organizer->getId(), OrganizerDomainObject::class, 'ORGANIZER_LOGO', self::ORGANIZER_LOGO, $event->getAccountId());
        $this->addImage($organizer->getId(), OrganizerDomainObject::class, 'ORGANIZER_EMAIL_LOGO', self::ORGANIZER_EMAIL_LOGO, $event->getAccountId());

        $html = (new OrderCancelled($order, $event, $organizer, $settings))->render();

        self::assertStringContainsString('https://cdn.test/'.self::ORGANIZER_EMAIL_LOGO, $html);
        self::assertStringNotContainsString(self::PLATFORM_LOGO, $html);
        self::assertStringContainsString('alt="Test Organizer"', $html);
    }

    public function test_organizer_logo_is_used_without_any_email_logo(): void
    {
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase(price: 25);
        $this->addImage($organizer->getId(), OrganizerDomainObject::class, 'ORGANIZER_LOGO', self::ORGANIZER_LOGO, $event->getAccountId());

        $html = (new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer))->render();

        self::assertStringContainsString('https://cdn.test/'.self::ORGANIZER_LOGO, $html);
    }

    public function test_platform_logo_without_any_organizer_image(): void
    {
        [$order, , $event, $settings, $organizer] = $this->purchase();

        $mail = new OrderCancelled($order, $event, $organizer, $settings);

        self::assertStringEndsWith('/logos/'.self::PLATFORM_LOGO, $this->headerLogo($mail));
    }
}
