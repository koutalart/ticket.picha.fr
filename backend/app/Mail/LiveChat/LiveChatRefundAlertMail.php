<?php

declare(strict_types=1);

namespace HiEvents\Mail\LiveChat;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Currency;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundTicketDTO;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Sent to the organizer and to the PICHA watch address after the web chat refunded an order on its own.
 *
 * @uses /backend/resources/views/emails/live-chat/refund-alert.blade.php
 */
class LiveChatRefundAlertMail extends BaseMail
{
    /**
     * @param  Collection<int, LiveChatRefundTicketDTO>  $tickets
     * @param  Collection<int, LiveChatMessageDomainObject>  $transcript
     */
    public function __construct(
        private readonly OrganizerDomainObject $organizer,
        private readonly EventDomainObject $event,
        private readonly OrderDomainObject $order,
        private readonly Collection $tickets,
        private readonly float $amount,
        private readonly Collection $transcript,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Web chat: order :reference refunded (:amount)', [
                'reference' => $this->order->getPublicId(),
                'amount' => $this->formattedAmount(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.live-chat.refund-alert',
            with: [
                'organizerName' => $this->organizer->getName(),
                'eventTitle' => $this->event->getTitle(),
                'orderReference' => $this->order->getPublicId(),
                'buyerName' => trim($this->order->getFirstName().' '.$this->order->getLastName()),
                'buyerEmail' => $this->order->getEmail(),
                'amount' => $this->formattedAmount(),
                'tickets' => $this->tickets,
                'transcript' => $this->transcript,
                'orderUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ORGANIZER_ORDER_SUMMARY),
                    $this->event->getId(),
                    $this->order->getId(),
                ),
            ],
        );
    }

    private function formattedAmount(): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', Currency::format($this->amount, $this->order->getCurrency(), 'fr_FR'));
    }
}
