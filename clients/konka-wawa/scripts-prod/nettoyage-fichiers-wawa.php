<?php
// Konka Wawa (compte 6) : supprime du stockage les fichiers des images et vidéos remplacées
// (lignes déjà supprimées en base et dont le fichier n'est utilisé par aucune ligne active).
// Les fichiers en ligne ne sont pas touchés. Relançable : un fichier déjà absent est ignoré.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const ACCOUNT_ID = 6;
const DOSSIERS = ['event_cover/', 'event_cover_video/', 'event_share_image/'];

$actifs = DB::table('images')->whereNull('deleted_at')->pluck('path')->flip();

$anciens = DB::table('images')
    ->where('account_id', ACCOUNT_ID)
    ->whereNotNull('deleted_at')
    ->whereIn('type', ['EVENT_COVER', 'EVENT_COVER_VIDEO', 'EVENT_SHARE_IMAGE'])
    ->get(['path', 'disk', 'size'])
    ->unique('path');

$supprimes = 0; $octets = 0; $absents = 0;
foreach ($anciens as $image) {
    if (isset($actifs[$image->path]) || !\Illuminate\Support\Str::startsWith($image->path, DOSSIERS)) {
        continue;
    }
    $disque = Storage::disk($image->disk ?: 'public');
    if (!$disque->exists($image->path)) {
        $absents++;
        continue;
    }
    $disque->delete($image->path);
    $supprimes++;
    $octets += (int) $image->size;
}

$enLigne = DB::table('images')->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->get(['path', 'disk']);
$manquants = $enLigne->filter(fn ($i) => !Storage::disk($i->disk ?: 'public')->exists($i->path))->count();

echo 'Fichiers supprimés : '.$supprimes.' ('.round($octets / 1048576, 1).' Mo) | déjà absents : '.$absents."\n";
echo 'Fichiers en ligne vérifiés : '.$enLigne->count().' | manquants : '.$manquants."\n";
