<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Models\Event;
use HiEvents\Services\Domain\Event\DuplicateEventService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class DuplicateEventTicketImagesTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function addImage(int $eventId, ImageType $type, string $path): void
    {
        DB::table('images')->insert([
            'entity_id' => $eventId,
            'entity_type' => EventDomainObject::class,
            'type' => $type->name,
            'disk' => 'public',
            'path' => $path,
            'filename' => basename($path),
            'size' => 1000,
            'mime_type' => 'image/png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function duplicate(bool $duplicateTicketLogo): int
    {
        config(['filesystems.public' => 'public']);
        Storage::fake('public');
        [$event, , , $user] = $this->createEventWithProduct();
        $this->actingAs($user);
        Event::whereKey($event->id)->update(['timezone' => 'Indian/Mayotte']);
        $this->addImage($event->id, ImageType::TICKET_LOGO, 'ticket_logo/logo.png');
        $this->addImage($event->id, ImageType::TICKET_SPONSOR_LOGO, 'ticket_sponsor_logo/sponsor.png');

        return app(DuplicateEventService::class)->duplicateEvent(
            eventId: (string) $event->id,
            accountId: (string) $event->account_id,
            title: 'Copie',
            startDate: now()->addMonth()->toDateTimeString(),
            duplicateTicketLogo: $duplicateTicketLogo,
            duplicateWebhooks: false,
            duplicateAffiliates: false,
        )->getId();
    }

    private function imagePath(int $eventId, ImageType $type): ?string
    {
        return DB::table('images')
            ->where(['entity_id' => $eventId, 'entity_type' => EventDomainObject::class, 'type' => $type->name])
            ->whereNull('deleted_at')
            ->value('path');
    }

    public function test_ticket_and_sponsor_logos_are_copied(): void
    {
        $newEventId = $this->duplicate(duplicateTicketLogo: true);

        self::assertSame('ticket_logo/logo.png', $this->imagePath($newEventId, ImageType::TICKET_LOGO));
        self::assertSame('ticket_sponsor_logo/sponsor.png', $this->imagePath($newEventId, ImageType::TICKET_SPONSOR_LOGO));
    }

    public function test_no_ticket_logo_is_copied_when_the_option_is_off(): void
    {
        $newEventId = $this->duplicate(duplicateTicketLogo: false);

        self::assertNull($this->imagePath($newEventId, ImageType::TICKET_LOGO));
        self::assertNull($this->imagePath($newEventId, ImageType::TICKET_SPONSOR_LOGO));
    }
}
