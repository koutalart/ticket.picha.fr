<?php

declare(strict_types=1);

namespace HiEvents\Resources\LiveChat;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundStepDTO;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundTicketDTO;

/**
 * @mixin LiveChatRefundStepDTO
 */
class LiveChatRefundStepResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'step' => $this->step,
            'messages' => LiveChatMessageResource::collection($this->messages)->toArray($request),
            'tickets' => $this->tickets->map(fn (LiveChatRefundTicketDTO $ticket) => [
                'attendee_id' => $ticket->attendeeId,
                'name' => $ticket->name,
                'category' => $ticket->category,
                'amount' => $ticket->amount,
                'amount_minor' => (int) round($ticket->amount * 100),
            ])->values()->all(),
            'max_tickets' => $this->maxTickets,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'error' => $this->error,
            'attempts_left' => $this->attemptsLeft,
            'ask_contact' => $this->askContact,
            'contact_given' => $this->session->getVisitorPhone() !== null || $this->session->getVisitorEmail() !== null,
        ];
    }
}
