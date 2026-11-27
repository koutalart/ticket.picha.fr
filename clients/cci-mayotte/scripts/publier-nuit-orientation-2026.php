<?php
// Événement 32 publié (LIVE) sur « mets en ligne » de Jo le 27/11/2026.
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventStatusDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventStatusHandler;
use Illuminate\Support\Facades\DB;

$e = DB::table('events')->where('id', 32)->where('account_id', 18)->where('organizer_id', 22)->whereNull('deleted_at')->first();
if (!$e || $e->title !== 'La Nuit de l\'Orientation') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(22));
app(UpdateEventStatusHandler::class)->handle(new UpdateEventStatusDTO(status: 'LIVE', eventId: 32, accountId: 18));
$e = DB::table('events')->where('id', 32)->first();
echo "Événement {$e->id} | {$e->status} | slug ".($e->slug ?? '?')."\n";
