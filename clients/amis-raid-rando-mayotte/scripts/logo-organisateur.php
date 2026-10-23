<?php
// Pose le logo ARRM (site + e-mails) sur l'organisateur 14 du compte 3. Rejouable : remplace l'image existante.
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;

$accountId = 3;
$organizerId = 14;
$userId = 6;
$path = '/tmp/logo-arrm.png';

$org = DB::table('organizers')->where('id', $organizerId)->first();
if (!$org || $org->account_id !== $accountId || $org->name !== 'Amis Raid Rando de Mayotte') {
    throw new Exception('organisateur inattendu');
}
auth()->setUser(User::findOrFail($userId));

foreach ([ImageType::ORGANIZER_LOGO, ImageType::ORGANIZER_EMAIL_LOGO] as $type) {
    $copy = sys_get_temp_dir() . '/arrm-' . $type->name . '.png';
    copy($path, $copy);
    $img = app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: $userId,
        accountId: $accountId,
        image: new UploadedFile($copy, 'logo-arrm.png', 'image/png', null, true),
        imageType: $type,
        entityId: $organizerId,
    ));
    echo "{$type->name} : image {$img->getId()} {$img->getPath()}\n";
}
