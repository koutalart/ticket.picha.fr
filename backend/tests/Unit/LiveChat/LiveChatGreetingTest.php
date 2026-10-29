<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use Tests\TestCase;

class LiveChatGreetingTest extends TestCase
{
    public function test_greeting_names_the_event_and_the_venue_with_tu(): void
    {
        $this->assertSame(
            'Bonjour ! Une question sur Halloween Party au Five ? Je te réponds tout de suite.',
            LiveChatService::greeting(true, 'Halloween Party', 'Five'),
        );
    }

    public function test_greeting_names_the_event_and_the_venue_with_vous(): void
    {
        $this->assertSame(
            'Bonjour ! Une question sur Halloween Party au Five ? Je vous réponds tout de suite.',
            LiveChatService::greeting(false, 'Halloween Party', 'Five'),
        );
    }

    public function test_greeting_without_venue_names_only_the_event(): void
    {
        $this->assertSame(
            'Bonjour ! Une question sur Halloween Party ? Je te réponds tout de suite.',
            LiveChatService::greeting(true, 'Halloween Party', '  '),
        );
        $this->assertSame(
            'Bonjour ! Une question sur Halloween Party ? Je vous réponds tout de suite.',
            LiveChatService::greeting(false, 'Halloween Party'),
        );
    }

    public function test_greeting_without_event_stays_generic(): void
    {
        $this->assertSame(
            'Bonjour ! Pose-moi ta question sur nos événements, je te réponds tout de suite.',
            LiveChatService::greeting(true),
        );
        $this->assertSame(
            'Bonjour ! Posez-moi votre question sur nos événements, je vous réponds tout de suite.',
            LiveChatService::greeting(false, ''),
        );
    }

    public function test_venue_comes_from_the_event_then_from_its_settings(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setIsOnlineEvent(false)
            ->setLocationDetails(['venue_name' => 'Salle des fêtes']);

        $event = (new EventDomainObject)->setLocationDetails(['venue_name' => ' Five '])->setEventSettings($settings);
        $this->assertSame('Five', LiveChatService::venueName($event));

        $event = (new EventDomainObject)->setLocationDetails(null)->setEventSettings($settings);
        $this->assertSame('Salle des fêtes', LiveChatService::venueName($event));
    }

    public function test_online_event_has_no_venue(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setIsOnlineEvent(true)
            ->setLocationDetails(['venue_name' => 'Salle des fêtes']);

        $this->assertNull(LiveChatService::venueName((new EventDomainObject)->setEventSettings($settings)));
    }
}
