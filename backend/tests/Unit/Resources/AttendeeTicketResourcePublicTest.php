<?php

namespace Tests\Unit\Resources;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Resources\Attendee\AttendeeTicketResourcePublic;
use Illuminate\Http\Request;
use Tests\TestCase;

class AttendeeTicketResourcePublicTest extends TestCase
{
    public function test_ticket_link_does_not_expose_the_email(): void
    {
        $attendee = (new AttendeeDomainObject)
            ->setId(1)
            ->setEmail('buyer@example.com')
            ->setFirstName('Awa')
            ->setLastName('M')
            ->setShortId('a_x')
            ->setPublicId('A-1')
            ->setStatus('ACTIVE')
            ->setProductId(1)
            ->setProductPriceId(1)
            ->setLocale('fr');

        $data = (new AttendeeTicketResourcePublic($attendee))->toArray(new Request);

        self::assertArrayNotHasKey('email', $data);
        self::assertSame('Awa', $data['first_name']);
        self::assertStringNotContainsString('buyer@example.com', json_encode($data));
    }
}
