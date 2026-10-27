<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\SmsMessageChannel;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\EventDailyReportDomainObject;
use HiEvents\DomainObjects\Generated\EventDailyReportDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\LiveChatRefundRequestStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsRegistrationConfirmationStatus;
use HiEvents\Models\EventDailyReport;
use HiEvents\Repository\Interfaces\EventDailyReportRepositoryInterface;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventActivityStatsDTO;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventRegistrationStatsDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<EventDailyReportDomainObject>
 */
class EventDailyReportRepository extends BaseRepository implements EventDailyReportRepositoryInterface
{
    protected function getModel(): string
    {
        return EventDailyReport::class;
    }

    public function getDomainObject(): string
    {
        return EventDailyReportDomainObject::class;
    }

    public function claimDay(int $reportId, string $localDate): bool
    {
        return EventDailyReport::query()
            ->whereKey($reportId)
            ->where(fn ($query) => $query
                ->whereNull(EventDailyReportDomainObjectAbstract::LAST_SENT_ON)
                ->orWhere(EventDailyReportDomainObjectAbstract::LAST_SENT_ON, '<', $localDate))
            ->update([
                EventDailyReportDomainObjectAbstract::LAST_SENT_ON => $localDate,
                EventDailyReportDomainObjectAbstract::UPDATED_AT => now()->toDateTimeString(),
            ]) > 0;
    }

    /**
     * Paid registrations are the active attendees of completed orders that are not fully refunded, for ticket products
     * only: options (GENERAL products) and refund protection products are left out. Their revenue is the unit price
     * of their order line, which holds neither the platform fee nor the other fees and taxes.
     */
    public function findRegistrationStats(int $eventId, string $dayStartUtc, string $dayEndUtc, string $nowUtc): EventRegistrationStatsDTO
    {
        $unitPrice = '(SELECT order_items.price FROM order_items'
            .' WHERE order_items.order_id = attendees.order_id'
            .' AND order_items.product_price_id = attendees.product_price_id'
            .' AND order_items.deleted_at IS NULL ORDER BY order_items.id LIMIT 1)';

        $row = $this->paidAttendees($eventId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(*) FILTER (WHERE attendees.created_at >= ? AND attendees.created_at < ?) AS today', [$dayStartUtc, $dayEndUtc])
            ->selectRaw('COALESCE(SUM('.$unitPrice.'), 0) AS revenue')
            ->first();

        $openCarts = DB::table('orders')
            ->where('orders.event_id', $eventId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::RESERVED->name)
            ->where('orders.reserved_until', '>', $nowUtc)
            ->count();

        return new EventRegistrationStatsDTO(
            totalRegistrations: (int) ($row->total ?? 0),
            todayRegistrations: (int) ($row->today ?? 0),
            revenue: round((float) ($row->revenue ?? 0), 2),
            openCarts: $openCarts,
        );
    }

    public const PENDING_REFUND_STATUSES = [
        LiveChatRefundRequestStatus::VERIFIED,
        LiveChatRefundRequestStatus::AWAITING_APPROVAL,
        LiveChatRefundRequestStatus::PROCESSING,
        LiveChatRefundRequestStatus::HANDED_OFF,
    ];

    private const OPT_OUT_WORDS = ['STOP', 'STOP SMS', 'ARRET', 'ARRÊT'];

    public function findActivity(int $eventId, int $organizerId, string $dayStartUtc, string $dayEndUtc, array $excludedPhones): EventActivityStatsDTO
    {
        $confirmations = DB::table('sms_registration_confirmations')
            ->where('event_id', $eventId)
            ->where('status', SmsRegistrationConfirmationStatus::SENT->name)
            ->where('created_at', '>=', $dayStartUtc)
            ->where('created_at', '<', $dayEndUtc)
            ->count();

        $chats = DB::table('live_chat_messages')
            ->join('live_chat_sessions', 'live_chat_sessions.id', '=', 'live_chat_messages.live_chat_session_id')
            ->where('live_chat_sessions.event_id', $eventId)
            ->where('live_chat_messages.author_type', LiveChatAuthorType::VISITOR->name)
            ->where('live_chat_messages.created_at', '>=', $dayStartUtc)
            ->where('live_chat_messages.created_at', '<', $dayEndUtc)
            ->groupBy('live_chat_sessions.id', 'live_chat_sessions.sms_conversation_id', 'live_chat_sessions.follow_up_reason')
            ->select('live_chat_sessions.sms_conversation_id', 'live_chat_sessions.follow_up_reason AS reason')
            ->selectRaw("STRING_AGG(live_chat_messages.content, ' ' ORDER BY live_chat_messages.id) AS texts")
            ->get();

        $chatConversationIds = $chats->pluck('sms_conversation_id')->filter()->map(fn ($id) => (int) $id)->all();

        $sentAt = 'COALESCE(sms_messages.provider_timestamp, sms_messages.created_at)';
        $sms = DB::table('sms_messages')
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->where('sms_messages.organizer_id', $organizerId)
            ->whereColumn('sms_conversations.organizer_id', 'sms_messages.organizer_id')
            ->where('sms_messages.direction', SmsMessageDirection::INBOUND->name)
            ->where('sms_messages.is_supervision', false)
            ->where('sms_conversations.is_team', false)
            ->where(fn (Builder $query) => $query
                ->whereNull('sms_messages.channel')
                ->orWhere('sms_messages.channel', '!=', SmsMessageChannel::WEB_CHAT->name))
            ->whereRaw($sentAt.' >= ?', [$dayStartUtc])
            ->whereRaw($sentAt.' < ?', [$dayEndUtc])
            ->whereRaw('UPPER(TRIM(sms_messages.content)) NOT IN ('.implode(', ', array_fill(0, count(self::OPT_OUT_WORDS), '?')).')', self::OPT_OUT_WORDS)
            ->when($excludedPhones !== [], fn (Builder $query) => $query->whereNotIn('sms_conversations.phone', $excludedPhones))
            ->when($chatConversationIds !== [], fn (Builder $query) => $query->whereNotIn('sms_conversations.id', $chatConversationIds))
            ->groupBy('sms_conversations.id', 'sms_conversations.needs_human_reason')
            ->select('sms_conversations.needs_human_reason AS reason')
            ->selectRaw("STRING_AGG(sms_messages.content, ' ' ORDER BY sms_messages.id) AS texts")
            ->get();

        $exchanges = $chats->concat($sms)
            ->map(fn (object $row) => trim(($row->reason ?? '').' '.($row->texts ?? '')))
            ->values()
            ->all();

        $pendingRefunds = DB::table('live_chat_refund_requests')
            ->where('event_id', $eventId)
            ->whereIn('status', array_map(fn (LiveChatRefundRequestStatus $status) => $status->name, self::PENDING_REFUND_STATUSES))
            ->count();

        return new EventActivityStatsDTO(
            confirmationsSent: $confirmations,
            exchanges: $exchanges,
            pendingRefunds: $pendingRefunds,
        );
    }

    private function paidAttendees(int $eventId): Builder
    {
        return DB::table('attendees')
            ->join('orders', 'orders.id', '=', 'attendees.order_id')
            ->join('products', 'products.id', '=', 'attendees.product_id')
            ->where('attendees.event_id', $eventId)
            ->whereNull('attendees.deleted_at')
            ->where('attendees.status', AttendeeStatus::ACTIVE->name)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::COMPLETED->name)
            ->where(fn (Builder $query) => $query
                ->whereNull('orders.refund_status')
                ->orWhere('orders.refund_status', '!=', OrderRefundStatus::REFUNDED->name))
            ->where('products.product_type', ProductType::TICKET->name)
            ->where('products.is_refund_protection', false);
    }
}
