<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerContactDTO;
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
}
