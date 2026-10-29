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
            'Bonjour ! Une question sur Halloween au 5/5 ? Je te réponds tout de suite.',
            LiveChatService::greeting(true, 'Halloween', 'Le 5/5 - Bar / Restaurant / Live'),
        );
    }

    public function test_greeting_names_the_event_and_the_venue_with_vous(): void
    {
        $this->assertSame(
            'Bonjour ! Une question sur Halloween à Mamoudzou ? Je vous réponds tout de suite.',
            LiveChatService::greeting(false, 'Halloween', 'Mamoudzou'),
        );
    }

    public function test_venue_is_cut_at_the_first_separator(): void
    {
        $this->assertSame('Le 5/5', LiveChatService::shortVenueName('  Le 5/5 - Bar / Restaurant / Live '));
        $this->assertSame('Le 5/5', LiveChatService::shortVenueName('Le 5/5-Bar'));
        $this->assertSame('La Plage', LiveChatService::shortVenueName('La Plage | Sakouli'));
        $this->assertSame('Le Five', LiveChatService::shortVenueName('Le Five, Mamoudzou'));
        $this->assertSame('Le Five', LiveChatService::shortVenueName('Le Five (Kawéni)'));
        $this->assertSame('Le Five', LiveChatService::shortVenueName('Le Five – Kawéni'));
    }

    public function test_french_article_is_contracted(): void
    {
        $this->assertSame('au 5/5', LiveChatService::frenchPlace('Le 5/5'));
        $this->assertSame('au Five', LiveChatService::frenchPlace('le Five'));
        $this->assertSame('à la Plage', LiveChatService::frenchPlace('La Plage'));
        $this->assertSame('aux Arènes', LiveChatService::frenchPlace('LES Arènes'));
        $this->assertSame("à l'Escale", LiveChatService::frenchPlace("L'Escale"));
        $this->assertSame("à l'Escale", LiveChatService::frenchPlace('L’Escale'));
        $this->assertSame('à Mamoudzou', LiveChatService::frenchPlace('Mamoudzou'));
        $this->assertSame('à Lagon Bleu', LiveChatService::frenchPlace('Lagon Bleu'));
    }

    public function test_too_long_venue_is_left_out(): void
    {
        $this->assertSame('', LiveChatService::shortVenueName('Salle polyvalente du complexe sportif de Cavani'));
        $this->assertSame(
            'Bonjour ! Une question sur Halloween ? Je te réponds tout de suite.',
            LiveChatService::greeting(true, 'Halloween', 'Salle polyvalente du complexe sportif de Cavani'),
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
