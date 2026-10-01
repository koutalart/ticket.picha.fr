<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\Ticket\TicketContentService;
use Illuminate\Filesystem\FilesystemManager;
use Mockery;
use Tests\TestCase;

class TicketContentServiceTest extends TestCase
{
    public function test_builds_french_ticket_content_from_domain_objects(): void
    {
        app()->setLocale('fr');

        $content = $this->service()->build(
            attendee: (new AttendeeDomainObject)->setPublicId('A-C369BTS')->setFirstName('Anli')->setLastName('Madi'),
            event: (new EventDomainObject)->setId(7)->setTitle('JPO Mayotte la 1ère')
                ->setStartDate('2026-09-05 17:00:00')->setEndDate('2026-09-06 02:30:00')->setTimezone('Indian/Mayotte'),
            eventSettings: (new EventSettingDomainObject)->setTicketSponsorName('Bé digital')
                ->setLocationDetails(['venue_name' => 'Le 5/5', 'city' => 'Mamoudzou']),
            organizer: (new OrganizerDomainObject)->setName('Mayotte la 1ère'),
            product: (new ProductDomainObject)->setTitle('Pass VIP'),
        );

        self::assertSame('A-C369BTS', $content->public_id);
        self::assertSame('JPO Mayotte la 1ère', $content->event_title);
        self::assertSame('Pass VIP', $content->product_title);
        self::assertSame('Anli Madi', $content->attendee_name);
        self::assertSame('Sam. 5 sept. 2026', $content->event_when);
        self::assertSame('20h00 - 05h30', $content->event_hours);
        self::assertSame('Le 5/5', $content->venue_name);
        self::assertSame('Mamoudzou', $content->venue_city);
        self::assertSame('Mayotte la 1ère', $content->organizer_name);
        self::assertSame('Bé digital', $content->sponsor_name);
        self::assertNull($content->event_logo_image);
        self::assertNull($content->sponsor_logo_image);
    }

    public function test_tolerates_missing_settings_organizer_and_dates(): void
    {
        $content = $this->service()->build(
            attendee: (new AttendeeDomainObject)->setPublicId('A-X')->setFirstName('Jane')->setLastName(''),
            event: (new EventDomainObject)->setId(7)->setTitle('JPO'),
            eventSettings: null,
            organizer: null,
            product: null,
        );

        self::assertSame('Jane', $content->attendee_name);
        self::assertSame('', $content->event_when);
        self::assertSame('', $content->product_title);
        self::assertSame('', $content->sponsor_name);
    }

    private function service(): TicketContentService
    {
        $images = Mockery::mock(ImageRepositoryInterface::class);
        $images->shouldReceive('findFirstWhere')->andReturnNull();

        return new TicketContentService($images, app(FilesystemManager::class));
    }
}
