<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerContactDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmsCustomerDirectoryRepository implements SmsCustomerDirectoryRepositoryInterface
{
    public function findCustomersWithPhone(int $organizerId): Collection
    {
        $phones = DB::table('question_answers')
            ->join('questions', 'questions.id', '=', 'question_answers.question_id')
            ->where('questions.type', 'PHONE')
            ->whereNull('question_answers.deleted_at')
            ->select('question_answers.order_id', DB::raw('question_answers.answer::text as phone'))
            ->unionAll(
                DB::table('box_office_sales')
                    ->whereNotNull('box_office_sales.order_id')
                    ->whereNotNull('box_office_sales.phone')
                    ->select('box_office_sales.order_id', 'box_office_sales.phone')
            );

        return DB::table('orders')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->joinSub($phones, 'phones', 'phones.order_id', '=', 'orders.id')
            ->where('events.organizer_id', $organizerId)
            ->whereNull('orders.deleted_at')
            ->whereNull('events.deleted_at')
            ->whereNotIn('orders.status', [OrderStatus::RESERVED->name, OrderStatus::ABANDONED->name])
            ->orderByDesc('orders.created_at')
            ->get([
                'phones.phone',
                'orders.first_name',
                'orders.last_name',
                'orders.email',
                'orders.created_at',
            ])
            ->map(fn (object $row) => new SmsCustomerContactDTO(
                organizerId: $organizerId,
                phone: trim((string) $row->phone, " \t\n\r\"[]"),
                firstName: $row->first_name,
                lastName: $row->last_name,
                email: $row->email,
                orderedAt: (string) $row->created_at,
            ));
    }

    public function findActiveAttendeesWithPhone(int $eventId): Collection
    {
        $phoneAnswer = fn () => DB::table('question_answers')
            ->join('questions', 'questions.id', '=', 'question_answers.question_id')
            ->where('questions.type', 'PHONE')
            ->whereNull('question_answers.deleted_at')
            ->orderByDesc('question_answers.id')
            ->limit(1)
            ->select(DB::raw('question_answers.answer::text'));

        $attendeePhone = $phoneAnswer()->whereColumn('question_answers.attendee_id', 'attendees.id');

        $orderPhone = $phoneAnswer()
            ->whereColumn('question_answers.order_id', 'attendees.order_id')
            ->whereNull('question_answers.attendee_id');

        $boxOfficePhone = DB::table('box_office_sales')
            ->whereColumn('box_office_sales.order_id', 'attendees.order_id')
            ->whereNotNull('box_office_sales.phone')
            ->where('box_office_sales.phone', '<>', '')
            ->orderByRaw('CASE WHEN box_office_sales.attendee_id = attendees.id THEN 0 ELSE 1 END')
            ->orderByDesc('box_office_sales.id')
            ->limit(1)
            ->select('box_office_sales.phone');

        return DB::table('attendees')
            ->join('orders', 'orders.id', '=', 'attendees.order_id')
            ->where('attendees.event_id', $eventId)
            ->where('attendees.status', AttendeeStatus::ACTIVE->name)
            ->whereNull('attendees.deleted_at')
            ->whereNull('orders.deleted_at')
            ->orderBy('attendees.id')
            ->select('attendees.id', 'attendees.first_name', 'attendees.last_name')
            ->selectSub($attendeePhone, 'attendee_phone')
            ->selectSub($orderPhone, 'order_phone')
            ->selectSub($boxOfficePhone, 'box_office_phone')
            ->get()
            ->map(function (object $row) {
                $phone = collect([$row->attendee_phone, $row->order_phone, $row->box_office_phone])
                    ->map(fn ($value) => trim((string) $value, " \t\n\r\"[]"))
                    ->first(fn (string $value) => $value !== '');

                return new SmsReminderAttendeeDTO(
                    attendeeId: (int) $row->id,
                    phone: $phone,
                    firstName: $row->first_name,
                    lastName: $row->last_name,
                );
            });
    }

    public function findConversationNames(int $organizerId): array
    {
        return DB::table('sms_conversations')
            ->where('organizer_id', $organizerId)
            ->whereNotNull('contact_name')
            ->where('contact_name', '<>', '')
            ->pluck('contact_name', 'phone')
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn (string $name) => $name !== '')
            ->all();
    }
}
