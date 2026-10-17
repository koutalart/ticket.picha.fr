<?php
// Konka Wawa (organisateur 9) : remplace le logo PNG de 329 Ko par la même image en webp 320 px (4 Ko).
// Même traitement que l'écran de l'organisateur ; remplace le logo précédent. Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const FICHIER = '/tmp/wawa-logo/logo-konka-wawa.webp';

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Konka Wawa' || !is_readable(FICHIER)) {
    throw new RuntimeException('Organisateur ou logo inattendu : rien n\'est envoyé');
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(CreateImageHandler::class)->handle(new CreateImageDTO(
    userId: USER_ID,
    accountId: ACCOUNT_ID,
    image: new UploadedFile(FICHIER, basename(FICHIER), 'image/webp', null, true),
    imageType: ImageType::ORGANIZER_LOGO,
    entityId: ORGANIZER_ID,
));

$logo = DB::table('images')->where('entity_id', ORGANIZER_ID)->where('type', 'ORGANIZER_LOGO')->whereNull('deleted_at')->get(['path', 'size']);
echo 'Logos actifs : '.$logo->count().' | '.$logo->map(fn ($l) => $l->path.' ('.round($l->size / 1024).' Ko)')->implode(', ')."\n";
