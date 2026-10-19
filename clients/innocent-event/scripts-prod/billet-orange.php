<?php
// Halloween (événement 7) : couleur du billet passée à l'orange du flyer (#F7941D). Relançable.
use Illuminate\Support\Facades\DB;

$s = DB::table('event_settings')->where('event_id', 7)->first();
if (!$s || DB::table('events')->where('id', 7)->value('title') !== 'Halloween') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$design = json_decode($s->ticket_design_settings ?? '{}', true) ?: [];
$design['accent_color'] = '#F7941D';
DB::table('event_settings')->where('event_id', 7)->update(['ticket_design_settings' => json_encode($design), 'updated_at' => now()]);
echo 'Billet : '.DB::table('event_settings')->where('event_id', 7)->value('ticket_design_settings')."\n";
