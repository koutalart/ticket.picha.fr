<?php
// Halloween (événement 7) : couverture plein cadre et image de partage, sans billetterie, infoline ni MQT. Relançable.
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

if (DB::table('events')->where('id', 7)->where('organizer_id', 4)->where('account_id', 3)->value('title') !== 'Halloween') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
foreach (['halloween-couverture-v4.jpg', 'halloween-partage-v4.jpg'] as $f) {
    if (!is_readable('/tmp/innocent-couv/'.$f)) throw new RuntimeException('Fichier manquant : '.$f);
}
auth()->setUser(\HiEvents\Models\User::findOrFail(6));
foreach ([[ImageType::EVENT_COVER, 'halloween-couverture-v4.jpg'], [ImageType::EVENT_SHARE_IMAGE, 'halloween-partage-v4.jpg']] as [$type, $f]) {
    app(CreateImageHandler::class)->handle(new CreateImageDTO(userId: 6, accountId: 3,
        image: new UploadedFile('/tmp/innocent-couv/'.$f, $f, 'image/jpeg', null, true), imageType: $type, entityId: 7));
}
foreach (DB::table('images')->where('entity_id', 7)->where('entity_type', 'like', '%EventDomainObject')->whereIn('type', ['EVENT_COVER', 'EVENT_SHARE_IMAGE'])->whereNull('deleted_at')->get(['type', 'path']) as $i) {
    echo $i->type.' | '.$i->path."\n";
}
