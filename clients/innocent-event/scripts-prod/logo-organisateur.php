<?php
// Innocent Event (compte 3, organisateur 4) : logo de l'organisateur (logo blanc sur fond noir). Relançable.
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

$org = DB::table('organizers')->where('id', 4)->where('account_id', 3)->whereNull('deleted_at')->first();
if (!$org || $org->name !== 'Innocent Event' || !is_readable('/tmp/logo-innocent-organisateur.png')) {
    throw new RuntimeException('Organisateur ou fichier inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(6));
app(CreateImageHandler::class)->handle(new CreateImageDTO(
    userId: 6,
    accountId: 3,
    image: new UploadedFile('/tmp/logo-innocent-organisateur.png', 'logo-innocent-organisateur.png', 'image/png', null, true),
    imageType: ImageType::ORGANIZER_LOGO,
    entityId: 4,
));
echo 'Images organisateur : '.DB::table('images')->where('entity_id', 4)->where('entity_type', 'like', '%OrganizerDomainObject')->whereNull('deleted_at')->pluck('type')->implode(', ')."\n";
