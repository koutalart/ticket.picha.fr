<?php
// Salon Mayotte Recrute : adresse de l'événement 13 (en-tête de la page) et logo carré de l'organisateur 10. Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 7;
const ORGANIZER_ID = 10;
const EVENT_ID = 13;
const USER_ID = 11;
const LOGO = '/tmp/picto-mayotte-recrute.png';

if (DB::table('events')->where('id', EVENT_ID)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->value('title') !== 'Salon Mayotte Recrute 2026' || !is_readable(LOGO)) {
    throw new RuntimeException('Contexte inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: ['location_details' => [
        'venue_name' => 'Gymnases du lycée des Lumières',
        'address_line_1' => 'Rue de la Geôle, Kawéni',
        'address_line_2' => null,
        'city' => 'Mamoudzou',
        'state_or_region' => null,
        'zip_or_postal_code' => '97600',
        'country' => 'YT',
    ]],
));

app(CreateImageHandler::class)->handle(new CreateImageDTO(
    userId: USER_ID,
    accountId: ACCOUNT_ID,
    image: new UploadedFile(LOGO, 'picto-mayotte-recrute.png', 'image/png', null, true),
    imageType: ImageType::ORGANIZER_LOGO,
    entityId: ORGANIZER_ID,
));

echo 'Adresse : '.DB::table('event_settings')->where('event_id', EVENT_ID)->value('location_details')."\n";
echo 'Logos actifs : '.DB::table('images')->where('entity_id', ORGANIZER_ID)->where('type', 'ORGANIZER_LOGO')->whereNull('deleted_at')->count()."\n";
