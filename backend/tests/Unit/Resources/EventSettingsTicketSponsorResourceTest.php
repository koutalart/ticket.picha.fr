<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Resources\Event\EventSettingsResourcePublic;
use Illuminate\Http\Request;
use Tests\TestCase;

class EventSettingsTicketSponsorResourceTest extends TestCase
{
    public function test_public_settings_expose_ticket_sponsor_name(): void
    {
        $settings = (new EventSettingDomainObject)->setTicketSponsorName('Bé digital');

        $public = (new EventSettingsResourcePublic($settings))->toArray(new Request);
        $private = (new EventSettingsResource($settings))->toArray(new Request);

        self::assertSame('Bé digital', $public['ticket_sponsor_name']);
        self::assertSame('Bé digital', $private['ticket_sponsor_name']);
    }
}
