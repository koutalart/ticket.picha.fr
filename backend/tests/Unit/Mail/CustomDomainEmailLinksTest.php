<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\BaseMail;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Order\OrderSummary;
use Illuminate\Support\Collection;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

/**
 * Participants of an organizer with its own domain never receive a ticket.picha.fr link.
 */
class CustomDomainEmailLinksTest extends TestCase
{
    use FakesPublicBaseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://ticket.picha.fr', 'app.email_logo_link_url' => 'https://ticket.picha.fr']);
        $this->fakePublicBaseUrl([8 => 'mayottela1ere.fr']);
    }

    public function test_order_confirmation_links_to_the_custom_domain(): void
    {
        [$order, , $event, $settings, $organizer] = $this->purchase(organizerId: 8);

        $data = $this->viewData(new OrderSummary($order, $event, $organizer, $settings, null));

        $this->assertSame('https://mayottela1ere.fr/checkout/14/o_abc/summary', $data['orderUrl']);
        $this->assertSame('https://mayottela1ere.fr', $data['logoLinkUrl']);
    }

    public function test_ticket_email_links_to_the_custom_domain(): void
    {
        [$order, $attendee, $event, $settings, $organizer] = $this->purchase(organizerId: 8);

        $single = $this->viewData(new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer));
        $several = $this->viewData(new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer, null, [$attendee, $attendee]));

        $this->assertSame('https://mayottela1ere.fr/product/14/a_xyz', $single['ticketUrl']);
        $this->assertSame('https://mayottela1ere.fr/checkout/14/o_abc/summary', $several['ticketUrl']);
        $this->assertSame('https://mayottela1ere.fr', $single['logoLinkUrl']);
    }

    public function test_failed_order_email_sends_back_to_the_event_on_the_custom_domain(): void
    {
        [$order, , $event, $settings, $organizer] = $this->purchase(organizerId: 8);

        $data = $this->viewData(new OrderFailed($order, $event, $organizer, $settings));

        $this->assertSame('https://mayottela1ere.fr/event/14/le-concert-des-generations', $data['eventUrl']);
    }

    public function test_organizer_without_custom_domain_keeps_the_platform_links(): void
    {
        [$order, , $event, $settings, $organizer] = $this->purchase(organizerId: 4);

        $data = $this->viewData(new OrderSummary($order, $event, $organizer, $settings, null));

        $this->assertSame('https://ticket.picha.fr/checkout/14/o_abc/summary', $data['orderUrl']);
        $this->assertNull($data['logoLinkUrl']);
    }

    private function viewData(BaseMail $mail): array
    {
        $mail->with($mail->content()->with);

        return $mail->buildViewData();
    }

    /**
     * @return array{0: OrderDomainObject, 1: AttendeeDomainObject, 2: EventDomainObject, 3: EventSettingDomainObject, 4: OrganizerDomainObject}
     */
    private function purchase(int $organizerId): array
    {
        $order = (new OrderDomainObject)
            ->setId(1)
            ->setEventId(14)
            ->setShortId('o_abc')
            ->setOrderItems(new Collection([(new OrderItemDomainObject)->setPrice(10)->setProductType('TICKET')]));

        return [
            $order,
            (new AttendeeDomainObject)->setId(2)->setEventId(14)->setShortId('a_xyz'),
            (new EventDomainObject)->setId(14)->setOrganizerId($organizerId)->setTitle('Le concert des générations'),
            (new EventSettingDomainObject)->setSupportEmail('contact@exemple.test'),
            (new OrganizerDomainObject)->setId($organizerId)->setName('Kultur\'m'),
        ];
    }
}
