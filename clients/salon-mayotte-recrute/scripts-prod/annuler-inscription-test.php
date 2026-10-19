<?php
// Salon Mayotte Recrute (événement 13) : annule l'inscription test o_7j5hXc2ZtaGWX (gratuite, faite par l'équipe PICHA).

use HiEvents\Services\Application\Handlers\Order\CancelOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CancelOrderDTO;
use Illuminate\Support\Facades\DB;

$o = DB::table('orders')->where('event_id', 13)->where('short_id', 'o_7j5hXc2ZtaGWX')->first(['id', 'status', 'total_gross']);
if (!$o || $o->status !== 'COMPLETED' || (float) $o->total_gross !== 0.0) {
    throw new RuntimeException('Commande inattendue : rien n\'est annulé');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(11));
app(CancelOrderHandler::class)->handle(new CancelOrderDTO(eventId: 13, orderId: $o->id));
echo 'Commande : '.DB::table('orders')->where('id', $o->id)->value('status').' | participants actifs : '.DB::table('attendees')->where('order_id', $o->id)->where('status', 'ACTIVE')->count()."\n";
