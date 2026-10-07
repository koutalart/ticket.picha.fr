<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Models\Attendee;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class AttendeeTicketMailRenderTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function mail(float $price, int $eventId = 6, ?RenderedEmailTemplateDTO $template = null): AttendeeTicketMail
    {
        [$eventModel, $product, $productPrice] = $this->createEventWithProduct();

        $orderModel = Order::create([
            'event_id' => $eventModel->id,
            'total_gross' => $price,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        $attendeeModel = Attendee::create([
            'event_id' => $eventModel->id,
            'order_id' => $orderModel->id,
            'product_id' => $product->id,
            'product_price_id' => $productPrice->id,
            'status' => 'ACTIVE',
            'email' => 'amina@example.test',
            'first_name' => 'Amina',
            'last_name' => 'Test',
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);

        $order = OrderDomainObject::hydrateFromModel($orderModel)
            ->setOrderItems(new Collection([(new OrderItemDomainObject)->setProductType('TICKET')->setPrice($price)->setProductPriceId($productPrice->id)->setItemName('Entrée libre')]));

        $event = EventDomainObject::hydrateFromModel($eventModel)
            ->setId($eventId)
            ->setTitle('Journée Portes Ouvertes');

        $organizer = OrganizerDomainObject::hydrateFromModel(Organizer::findOrFail($eventModel->organizer_id))
            ->setImages(new Collection([
                (new ImageDomainObject)->setType('ORGANIZER_COVER')->setPath('organizer_cover/cover.webp'),
                (new ImageDomainObject)->setType('ORGANIZER_LOGO')->setPath('organizer_logo/logo-mayotte.png'),
            ]));

        return new AttendeeTicketMail(
            $order,
            AttendeeDomainObject::hydrateFromModel($attendeeModel),
            $event,
            EventSettingDomainObject::hydrateFromModel(EventSetting::where('event_id', $eventModel->id)->firstOrFail()),
            $organizer,
            $template,
        );
    }

    public function test_default_ticket_email_renders_and_shows_organizer_logo_for_free_event(): void
    {
        $html = $this->mail(price: 0)->render();

        self::assertStringNotContainsString('x-mail::message', $html);
        self::assertStringContainsString('Journée Portes Ouvertes', $html);
        self::assertStringContainsString('organizer_logo/logo-mayotte.png', $html);
        self::assertStringNotContainsString('wikimedia', $html);
    }

    public function test_paid_ticket_email_keeps_platform_logo(): void
    {
        $html = $this->mail(price: 40)->render();

        self::assertStringNotContainsString('x-mail::message', $html);
        self::assertStringNotContainsString('organizer_logo/logo-mayotte.png', $html);
    }

    public function test_custom_template_email_renders(): void
    {
        $template = new RenderedEmailTemplateDTO(subject: 'Billet', body: '<p>Bienvenue à la JPO</p>');

        $targetHtml = $this->mail(price: 0, eventId: 6, template: $template)->render();
        self::assertStringNotContainsString('x-mail::message', $targetHtml);
        self::assertStringContainsString('Bienvenue à la JPO', $targetHtml);
        self::assertStringContainsString('organizer_logo/logo-mayotte.png', $targetHtml);

        $otherHtml = $this->mail(price: 0, eventId: 4, template: $template)->render();
        self::assertStringNotContainsString('x-mail::message', $otherHtml);
        self::assertStringNotContainsString('organizer_logo/logo-mayotte.png', $otherHtml);
    }
}
