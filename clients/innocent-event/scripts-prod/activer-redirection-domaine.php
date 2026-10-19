<?php
// Innocent Event (organisateur 4) : innocent976.yt redirige vers son événement en cours (PR #131 déployée).
// Ajoute redirect_to_current_event au contenu du site, sans toucher au reste. Relançable.
use Illuminate\Support\Facades\DB;

$org = DB::table('organizers')->where('id', 4)->where('account_id', 3)->whereNull('deleted_at')->first();
if (!$org || $org->name !== 'Innocent Event' || $org->custom_domain !== 'innocent976.yt') {
    throw new RuntimeException('Organisateur inattendu : rien n\'est modifié');
}
$contenu = json_decode($org->site_content ?? '{}', true) ?: [];
$contenu['redirect_to_current_event'] = true;
DB::table('organizers')->where('id', 4)->update(['site_content' => json_encode($contenu, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
echo 'Redirection activée : '.(json_decode(DB::table('organizers')->where('id', 4)->value('site_content'), true)['redirect_to_current_event'] ? 'oui' : 'NON')."\n";
