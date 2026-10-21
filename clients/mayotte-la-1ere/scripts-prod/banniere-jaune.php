<?php
// Organisateur 8 : bannière jaune de la chaîne recadrée (logo entier). Relançable.
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

if (DB::table('organizers')->where('id', 8)->where('account_id', 5)->value('name') !== 'Mayotte La 1ère' || !is_readable('/tmp/banniere-mayotte-jaune.jpg')) {
    throw new RuntimeException('Organisateur ou fichier inattendu : rien n\'est envoyé');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(8));
app(CreateImageHandler::class)->handle(new CreateImageDTO(
    userId: 8, accountId: 5,
    image: new UploadedFile('/tmp/banniere-mayotte-jaune.jpg', 'banniere-mayotte-la-1ere.jpg', 'image/jpeg', null, true),
    imageType: ImageType::ORGANIZER_COVER, entityId: 8,
));
echo 'Bannière | '.DB::table('images')->where('entity_id', 8)->where('type', 'ORGANIZER_COVER')->whereNull('deleted_at')->value('path')."\n";
